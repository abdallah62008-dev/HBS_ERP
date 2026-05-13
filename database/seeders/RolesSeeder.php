<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder for the nine default roles and their permission
 * assignments. Run after PermissionsSeeder.
 *
 * Super Admin is intentionally given an empty list here — User::hasPermission
 * short-circuits to true for super-admin so the role's permissions are
 * irrelevant. Keeping it empty avoids implying a finite permission set for
 * the role.
 *
 * The Marketer role gets only the slugs needed for the marketer portal.
 * Per-record ownership filtering (own orders, own wallet) lives in the
 * controllers / query scopes, not in the role grant.
 */
class RolesSeeder extends Seeder
{
    public function run(): void
    {
        $allPermissions = Permission::pluck('slug')->all();

        foreach ($this->roleDefinitions($allPermissions) as $slug => $def) {
            $role = Role::updateOrCreate(
                ['slug' => $slug],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                ],
            );

            $role->syncPermissionsBySlug($def['permissions']);
        }
    }

    /**
     * @param  array<int,string>  $allPermissions
     * @return array<string, array{name: string, description: string, permissions: array<int,string>}>
     */
    private function roleDefinitions(array $allPermissions): array
    {
        $admin = array_values(array_filter(
            $allPermissions,
            // Admin sees everything except destructive system controls reserved
            // for Super Admin.
            static fn (string $p) => ! in_array($p, [
                'permissions.manage',
                'year_end.manage',
                'backup.manage',
            ], true),
        ));

        $manager = [
            // Orders + customers + products read/edit
            'orders.view', 'orders.create', 'orders.edit', 'orders.export',
            'orders.print', 'orders.change_status', 'orders.view_profit',
            'customers.view', 'customers.create', 'customers.edit', 'customers.view_risk',
            'products.view', 'products.create', 'products.edit', 'products.edit_price',
            // Operations oversight
            'inventory.view', 'inventory.view_movements', 'inventory.count',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.approve',
            'suppliers.view', 'suppliers.create', 'suppliers.edit',
            'shipping.view', 'shipping.assign', 'shipping.print_label', 'shipping.update_status',
            'collections.view', 'collections.update',
            'returns.view', 'returns.create', 'returns.approve', 'returns.inspect',
            'expenses.view', 'expenses.create', 'expenses.edit',
            'ads.view', 'ads.create', 'ads.edit',
            'marketers.view', 'marketers.edit', 'marketers.wallet', 'marketers.statement', 'marketers.prices',
            // Reports
            'reports.view', 'reports.sales', 'reports.profit', 'reports.cash_flow',
            'reports.marketers', 'reports.shipping', 'reports.inventory',
            'reports.ads', 'reports.staff',
            // Approvals
            'approvals.manage',
            'audit_logs.view',
            // Tickets — full management for ops oversight (Phase 7).
            'tickets.view', 'tickets.create', 'tickets.edit', 'tickets.delete', 'tickets.manage',
            // Finance Phase 1 — managers see cashboxes and statements but don't mutate.
            'cashboxes.view', 'cashbox_transactions.view',
            // Finance Phase 2 — managers see payment methods and transfers (read-only).
            'payment_methods.view', 'cashbox_transfers.view',
            // Finance Phase 5A — managers own the approve/reject path on refunds.
            'refunds.view', 'refunds.create', 'refunds.approve', 'refunds.reject',
            // Finance Phase 5D — managers approve/reject marketer payouts but
            // do not execute the cashbox payment (separation of duties).
            'marketer_payouts.view', 'marketer_payouts.approve', 'marketer_payouts.reject',
            // Finance Phase 5E — managers see finance reports for oversight.
            'finance_reports.view',
        ];

        $orderAgent = [
            'orders.view', 'orders.create', 'orders.edit', 'orders.print',
            'orders.change_status',
            'customers.view', 'customers.create', 'customers.edit', 'customers.view_risk',
            'products.view',
            // Order agents handle customer-issue tickets at intake (Phase 7).
            'tickets.view', 'tickets.create',
            // Finance Phase 5A — order agents can request refunds at intake;
            // approve / reject stay with manager + admin.
            'refunds.view', 'refunds.create',
        ];

        $shippingAgent = [
            'orders.view', 'orders.print',
            'shipping.view', 'shipping.assign', 'shipping.print_label',
            'shipping.update_status', 'shipping.reconcile',
            'collections.view', 'collections.update', 'collections.reconcile',
            // Finance Phase 3 — shipping agents reconcile courier COD
            // settlements; that's the canonical use of these slugs.
            'collections.assign_cashbox', 'collections.reconcile_settlement',
        ];

        $warehouseAgent = [
            'orders.view', 'orders.print',
            'products.view',
            'inventory.view', 'inventory.adjust', 'inventory.count',
            'inventory.transfer', 'inventory.view_movements',
            'purchases.view',
            'shipping.view', 'shipping.print_label',
            'returns.view', 'returns.inspect',
            // Finance Phase 5A — read-only access for return context.
            'refunds.view',
        ];

        $accountant = [
            'orders.view', 'orders.view_profit', 'orders.export',
            'customers.view',
            'products.view', 'products.export',
            'inventory.view', 'inventory.view_movements',
            'purchases.view', 'purchases.create', 'purchases.edit', 'purchases.approve',
            'suppliers.view', 'suppliers.create', 'suppliers.edit',
            'collections.view', 'collections.update', 'collections.reconcile',
            'expenses.view', 'expenses.create', 'expenses.edit', 'expenses.export',
            'ads.view',
            'marketers.view', 'marketers.wallet', 'marketers.statement',
            'reports.view', 'reports.sales', 'reports.profit', 'reports.cash_flow',
            'reports.marketers', 'reports.shipping', 'reports.ads',
            'audit_logs.view',
            // Finance Phase 1 — accountant is the financial workhorse.
            // Deactivation reserved for admins per docs/finance Phase 0 matrix.
            'cashboxes.view', 'cashboxes.create', 'cashboxes.edit',
            'cashbox_transactions.view', 'cashbox_transactions.create',
            // Finance Phase 2 — accountant manages payment methods + transfers.
            // Deactivation of payment methods reserved for admin (per Phase 0 matrix).
            'payment_methods.view', 'payment_methods.create', 'payment_methods.edit',
            'cashbox_transfers.view', 'cashbox_transfers.create',
            // Finance Phase 3 — accountant posts collections to cashboxes.
            'collections.assign_cashbox', 'collections.reconcile_settlement',
            // Finance Phase 4 — accountant pays expenses from cashboxes.
            'expenses.assign_cashbox', 'expenses.post_to_cashbox',
            // Finance Phase 5A — accountant can view, create, and reject
            // refunds. `approve` is intentionally NOT granted (manager
            // approves; accountant executes — per Phase 0 separation of
            // duties).
            'refunds.view', 'refunds.create', 'refunds.reject',
            // Finance Phase 5B — accountant is the one who actually pays
            // the refund from the cashbox.
            'refunds.pay',
            // Finance Phase 5D — accountant requests payouts, rejects
            // unfunded ones, and executes the cashbox payment. `approve`
            // is intentionally NOT granted — it stays with management
            // (Phase 0 separation of duties matrix).
            'marketer_payouts.view', 'marketer_payouts.create',
            'marketer_payouts.reject', 'marketer_payouts.pay',
            // Finance Phase 5E — accountant is the primary consumer of
            // the cashbox-domain reports.
            'finance_reports.view',
        ];

        $marketer = [
            // Marketer portal: routes are gated by these and ALSO scoped to
            // the current marketer's records by query filters in controllers.
            'orders.view', 'orders.create',
            'marketers.wallet', 'marketers.statement',
            // Phase 7 — marketers can raise tickets for issues with their
            // own orders. tickets.manage is intentionally NOT granted, so
            // ownership scoping limits visibility to their own tickets.
            'tickets.view', 'tickets.create',
        ];

        $viewer = [
            'orders.view', 'customers.view', 'products.view',
            'inventory.view', 'purchases.view', 'suppliers.view',
            'shipping.view', 'collections.view', 'returns.view',
            'expenses.view', 'ads.view', 'marketers.view',
            'reports.view', 'reports.sales', 'reports.profit',
            'reports.inventory', 'reports.shipping',
            'tickets.view',
            // Finance Phase 5A — viewer can see refunds.
            'refunds.view',
        ];

        // Filter out any permission slugs that don't exist yet (forward-
        // compatible with later phases that add tickets, etc.)
        $only = static fn (array $list) => array_values(array_filter(
            array_values($list),
            static fn (string $p) => in_array($p, $allPermissions, true),
        ));

        return [
            'super-admin' => [
                'name' => 'Super Admin',
                'description' => 'Full access. Bypasses permission checks at the model level.',
                'permissions' => [],
            ],
            'admin' => [
                'name' => 'Admin',
                'description' => 'All operational access except system-critical controls.',
                'permissions' => $admin,
            ],
            'manager' => [
                'name' => 'Manager',
                'description' => 'Operations and approvals across modules.',
                'permissions' => $only($manager),
            ],
            'order-agent' => [
                'name' => 'Order Agent',
                'description' => 'Confirms and edits customer orders.',
                'permissions' => $only($orderAgent),
            ],
            'shipping-agent' => [
                'name' => 'Shipping Agent',
                'description' => 'Handles shipments and shipping company reconciliation.',
                'permissions' => $only($shippingAgent),
            ],
            'warehouse-agent' => [
                'name' => 'Warehouse Agent',
                'description' => 'Inventory, packing, and return inspection.',
                'permissions' => $only($warehouseAgent),
            ],
            'accountant' => [
                'name' => 'Accountant',
                'description' => 'Purchases, expenses, collections, and financial reports.',
                'permissions' => $only($accountant),
            ],
            'marketer' => [
                'name' => 'Marketer',
                'description' => 'External marketer. Sees only own orders, wallet, and statement.',
                'permissions' => $only($marketer),
            ],
            'viewer' => [
                'name' => 'Viewer',
                'description' => 'Read-only access across the system.',
                'permissions' => $only($viewer),
            ],
        ];
    }
}
