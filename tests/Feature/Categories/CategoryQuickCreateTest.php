<?php

namespace Tests\Feature\Categories;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for the inline "Quick Category" creation flow used by the
 * Product Add/Edit form. The same `POST /categories` endpoint serves
 * both the existing /categories management page (Inertia redirect) and
 * the inline modal (JSON response). These tests focus on the JSON path
 * — the redirect path is exercised indirectly by existing UAT.
 *
 * Permission model: categories piggyback on the products.* slugs by
 * design (see CategoriesController routes). users.create / users.edit
 * have nothing to do with categories.
 */
class CategoryQuickCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes between tests; seed the catalogue once.
        $this->seed();

        $this->admin = User::where('email', 'admin@hbs.local')->firstOrFail();
    }

    public function test_user_with_products_create_can_create_category_via_json(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(route('categories.store'), [
            'name' => 'Phone Cases',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['category' => ['id', 'name', 'parent_id', 'status']])
            ->assertJsonPath('category.name', 'Phone Cases')
            ->assertJsonPath('category.parent_id', null)
            ->assertJsonPath('category.status', 'Active');

        $this->assertDatabaseHas('categories', [
            'name' => 'Phone Cases',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_user_without_products_create_gets_403(): void
    {
        // Build a minimal user that holds no permissions. Use a non-system
        // role so we can detach products.create from it explicitly.
        $role = Role::create([
            'slug' => 'pcat-test-no-create',
            'name' => 'No-Create Test',
            'is_system' => false,
            'description' => 'Test role with no products.create.',
        ]);
        // Grant products.view (so they can hit /categories index endpoint
        // legitimately) but NOT products.create.
        $viewPerm = Permission::where('slug', 'products.view')->firstOrFail();
        $role->permissions()->attach($viewPerm->id);

        $user = User::create([
            'name' => 'Limited User',
            'email' => 'limited@hbs.local',
            'password' => Hash::make('LimitedPass1234'),
            'role_id' => $role->id,
            'status' => 'Active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('categories.store'), [
            'name' => 'Should Fail',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertStatus(403);

        $this->assertDatabaseMissing('categories', ['name' => 'Should Fail']);
    }

    public function test_duplicate_sibling_category_name_returns_validation_error(): void
    {
        $this->actingAs($this->admin);

        Category::create([
            'name' => 'Accessories',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $response = $this->postJson(route('categories.store'), [
            'name' => 'Accessories',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertSame(1, Category::where('name', 'Accessories')->whereNull('parent_id')->count());
    }

    public function test_same_name_under_different_parents_is_allowed(): void
    {
        $this->actingAs($this->admin);

        $electronics = Category::create([
            'name' => 'Electronics',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
        $home = Category::create([
            'name' => 'Home',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        // First "Cables" under Electronics — should succeed.
        $first = $this->postJson(route('categories.store'), [
            'name' => 'Cables',
            'parent_id' => $electronics->id,
            'status' => 'Active',
        ]);
        $first->assertStatus(201);

        // Second "Cables" under Home — should also succeed (different parent).
        $second = $this->postJson(route('categories.store'), [
            'name' => 'Cables',
            'parent_id' => $home->id,
            'status' => 'Active',
        ]);
        $second->assertStatus(201);

        $this->assertSame(2, Category::where('name', 'Cables')->count());
    }

    public function test_category_creation_writes_audit_log(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(route('categories.store'), [
            'name' => 'Audit Probe',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertStatus(201);

        $newId = $response->json('category.id');

        // CategoriesController::store calls AuditLogService::logModelChange
        // with module = 'products' (matches permission slug source).
        $this->assertDatabaseHas('audit_logs', [
            'module' => 'products',
            'action' => 'created',
            'record_type' => Category::class,
            'record_id' => $newId,
            'user_id' => $this->admin->id,
        ]);
    }

    public function test_missing_name_returns_json_validation_error(): void
    {
        $this->actingAs($this->admin);

        $response = $this->postJson(route('categories.store'), [
            'name' => '',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_non_json_request_still_redirects_to_categories_index(): void
    {
        // The SAME endpoint serves the /categories management page, which
        // expects an Inertia redirect (NOT JSON). The quick-add modal's
        // JSON branch must not regress that path.
        $this->actingAs($this->admin);

        $response = $this->post(route('categories.store'), [
            'name' => 'Redirect Path Category',
            'parent_id' => null,
            'status' => 'Active',
        ]);

        $response->assertRedirect(route('categories.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['name' => 'Redirect Path Category']);
    }

    public function test_product_create_page_ships_categories_for_quick_add(): void
    {
        // The quick-add modal lives on Products/Create — that page must
        // ship the `categories` prop the modal's dropdown is seeded from,
        // otherwise the inline flow has nothing to append to.
        $this->actingAs($this->admin);

        Category::create([
            'name' => 'Seeded For Product Page',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Products/Create')
                ->has('categories')
            );
    }

    public function test_non_json_validation_failure_redirects_back_with_errors(): void
    {
        // The /categories management page (sidebar menu) submits via
        // Inertia — a NON-JSON request. A validation failure on that
        // path must redirect BACK with the errors flashed to the
        // session so the page can render them inline; it must NOT
        // return a JSON 422 (which Inertia cannot consume).
        $this->actingAs($this->admin);

        Category::create([
            'name' => 'Existing Top Level',
            'parent_id' => null,
            'status' => 'Active',
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);

        $response = $this->from(route('categories.index'))
            ->post(route('categories.store'), [
                'name' => 'Existing Top Level', // duplicate sibling → fails
                'parent_id' => null,
                'status' => 'Active',
            ]);

        $response->assertRedirect(route('categories.index'));
        $response->assertSessionHasErrors(['name']);
        $this->assertSame(1, Category::where('name', 'Existing Top Level')->count());
    }

    public function test_non_json_request_without_permission_is_forbidden(): void
    {
        // Mirror of the JSON 403 test for the Inertia (non-JSON) path the
        // /categories page uses — a user lacking products.create cannot
        // create a category from the management page either.
        $role = Role::create([
            'slug' => 'pcat-test-no-create-web',
            'name' => 'No-Create Web Test',
            'is_system' => false,
            'description' => 'Test role with no products.create.',
        ]);
        $role->permissions()->attach(
            Permission::where('slug', 'products.view')->firstOrFail()->id
        );
        $user = User::create([
            'name' => 'Limited Web User',
            'email' => 'limited-web@hbs.local',
            'password' => Hash::make('LimitedPass1234'),
            'role_id' => $role->id,
            'status' => 'Active',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user);

        $this->post(route('categories.store'), [
            'name' => 'Web Should Fail',
            'parent_id' => null,
            'status' => 'Active',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('categories', ['name' => 'Web Should Fail']);
    }
}
