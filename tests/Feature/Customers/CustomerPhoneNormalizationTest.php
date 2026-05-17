<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Orders & Products O-2 — feature coverage for phone normalization on
 * the Customer controller path. Covers:
 *
 *  - Store: triple populated from primary + secondary inputs.
 *  - Update: triple refreshed when the operator edits.
 *  - Store/Update: 422 when the raw phone is invalid for the country.
 *  - Index search: a `+201...` query matches a customer whose
 *    `primary_phone` was typed as `01...`.
 *  - WhatsApp opt-out: when `primary_phone_whatsapp = false`, the
 *    `whatsappUrl()` accessor returns null.
 */
class CustomerPhoneNormalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_creating_customer_populates_phone_triple(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('customers.store'), $this->validPayload([
            'primary_phone' => '01012345678',
            'country_code' => '+20',
        ]))->assertRedirect();

        $c = Customer::firstOrFail();
        $this->assertSame('+20', $c->country_code);
        $this->assertSame('01012345678', $c->local_phone);
        $this->assertSame('+201012345678', $c->normalized_phone);
    }

    public function test_creating_customer_with_secondary_populates_secondary_triple(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('customers.store'), $this->validPayload([
            'primary_phone' => '01012345678',
            'secondary_phone' => '0501234567',
            'secondary_country_code' => '+966',
        ]))->assertRedirect();

        $c = Customer::firstOrFail();
        $this->assertSame('+966', $c->secondary_country_code);
        $this->assertSame('+966501234567', $c->secondary_normalized_phone);
    }

    public function test_creating_customer_with_invalid_phone_returns_422(): void
    {
        $this->actingAs($this->admin);

        $this->from(route('customers.create'))
            ->post(route('customers.store'), $this->validPayload([
                'primary_phone' => '0312345', // wrong length
                'country_code' => '+20',
            ]))
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors(['primary_phone']);

        $this->assertSame(0, Customer::count());
    }

    public function test_updating_customer_refreshes_phone_triple(): void
    {
        $this->actingAs($this->admin);

        // Seed a customer the old way (no triple) — simulates pre-O-2 data.
        $customer = Customer::create([
            'name' => 'Pre O-2 Customer',
            'primary_phone' => '01099999999',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Old Street',
            'created_by' => $this->admin->id,
        ]);
        $this->assertNull($customer->normalized_phone);

        $this->put(route('customers.update', $customer->id), $this->validPayload([
            'primary_phone' => '01088887777',
            'country_code' => '+20',
        ]))->assertRedirect();

        $customer->refresh();
        $this->assertSame('+201088887777', $customer->normalized_phone);
        $this->assertSame('+20', $customer->country_code);
    }

    public function test_index_search_matches_by_normalized_phone(): void
    {
        $this->actingAs($this->admin);

        $this->post(route('customers.store'), $this->validPayload([
            'name' => 'Searchable',
            'primary_phone' => '01055554444',
            'country_code' => '+20',
        ]))->assertRedirect();

        // Search by the E.164 form — the customer typed the local form.
        $this->get(route('customers.index', ['q' => '+201055554444']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Customers/Index')
                ->where('customers.data', fn ($data) => collect($data)->pluck('name')->all() === ['Searchable'])
            );
    }

    public function test_whatsapp_url_accessor_returns_link_when_opted_in(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('customers.store'), $this->validPayload([
            'primary_phone' => '01012345678',
            'country_code' => '+20',
            'primary_phone_whatsapp' => true,
        ]))->assertRedirect();

        $c = Customer::firstOrFail();
        $this->assertSame('https://wa.me/201012345678', $c->whatsappUrl());
    }

    public function test_whatsapp_url_accessor_returns_null_when_opted_out(): void
    {
        $this->actingAs($this->admin);
        $this->post(route('customers.store'), $this->validPayload([
            'primary_phone' => '01012345678',
            'country_code' => '+20',
            'primary_phone_whatsapp' => false,
        ]))->assertRedirect();

        $c = Customer::firstOrFail();
        $this->assertNull($c->whatsappUrl());
    }

    public function test_country_code_outside_allow_list_is_rejected(): void
    {
        $this->actingAs($this->admin);
        $this->from(route('customers.create'))
            ->post(route('customers.store'), $this->validPayload([
                'primary_phone' => '01012345678',
                'country_code' => '+1', // not in COUNTRY_RULES
            ]))
            ->assertSessionHasErrors(['country_code']);
    }

    /* ─── helpers ─── */

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Customer',
            'primary_phone' => '01012345678',
            'country_code' => '+20',
            'city' => 'Cairo',
            'country' => 'Egypt',
            'default_address' => '1 Test Street',
        ], $overrides);
    }
}
