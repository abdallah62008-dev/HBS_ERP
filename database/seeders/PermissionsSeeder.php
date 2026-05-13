<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder for the full permission catalogue defined in
 * 03_RBAC_SECURITY_AUDIT.md. Adding new permissions later just means
 * appending to the arrays below and re-running `php artisan db:seed
 * --class=PermissionsSeeder`. Existing rows are matched by slug so no
 * duplicates are produced.
 */
class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalogue() as $module => $items) {
            foreach ($items as $slug => $name) {
                Permission::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $name,
                        'module' => $module,
                        'description' => null,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function catalogue(): array
    {
        return [
            'orders' => [
                'orders.view' => 'View orders',
                'orders.create' => 'Create order',
                'orders.edit' => 'Edit order',
                'orders.delete' => 'Delete order',
                'orders.export' => 'Export orders',
                'orders.import' => 'Import orders',
                'orders.print' => 'Print order',
                'orders.change_status' => 'Change order status',
                'orders.view_profit' => 'View order profit',
            ],
            'customers' => [
                'customers.view' => 'View customers',
                'customers.create' => 'Create customer',
                'customers.edit' => 'Edit customer',
                'customers.delete' => 'Delete customer',
                'customers.view_risk' => 'View customer risk score',
            ],
            'products' => [
                'products.view' => 'View products',
                'products.create' => 'Create product',
                'products.edit' => 'Edit product',
                'products.edit_price' => 'Edit product price',
                'products.delete' => 'Delete product',
                'products.import' => 'Import products',
                'products.export' => 'Export products',
            ],
            'inventory' => [
                'inventory.view' => 'View inventory',
                'inventory.adjust' => 'Adjust inventory',
                'inventory.count' => 'Run stock count',
                'inventory.transfer' => 'Transfer stock between warehouses',
                'inventory.view_movements' => 'View inventory movements',
            ],
            'purchases' => [
                'purchases.view' => 'View purchase invoices',
                'purchases.create' => 'Create purchase invoice',
                'purchases.edit' => 'Edit purchase invoice',
                'purchases.approve' => 'Approve purchase invoice',
                'purchases.delete' => 'Delete purchase invoice',
                'suppliers.view' => 'View suppliers',
                'suppliers.create' => 'Create supplier',
                'suppliers.edit' => 'Edit supplier',
            ],
            'shipping' => [
                'shipping.view' => 'View shipping',
                'shipping.assign' => 'Assign to shipping company',
                'shipping.print_label' => 'Print 4x6 shipping label',
                'shipping.update_status' => 'Update shipping status',
                'shipping.reconcile' => 'Reconcile shipping settlements',
            ],
            'collections' => [
                'collections.view' => 'View collections',
                'collections.update' => 'Update collection',
                'collections.reconcile' => 'Reconcile collections',
                // Finance Phase 3 — finer-grained gates that complement the
                // existing slugs above. `assign_cashbox` covers picking the
                // cashbox / payment method on the collection row (no posting).
                // `reconcile_settlement` covers the action that actually
                // writes a cashbox_transaction.
                'collections.assign_cashbox' => 'Assign cashbox / payment method to a collection',
                'collections.reconcile_settlement' => 'Post a collection to a cashbox (settlement)',
            ],
            'returns' => [
                'returns.view' => 'View returns',
                'returns.create' => 'Create return',
                'returns.approve' => 'Approve return',
                'returns.inspect' => 'Inspect returned product',
            ],
            // Finance Phase 5A — Refunds foundation.
            // Finance Phase 5B — adds `refunds.pay` for the actual cashbox-OUT step.
            'refunds' => [
                'refunds.view' => 'View refunds',
                'refunds.create' => 'Create / edit / delete requested refund',
                'refunds.approve' => 'Approve requested refund',
                'refunds.reject' => 'Reject requested refund',
                'refunds.pay' => 'Pay an approved refund (write cashbox OUT)',
            ],
            'expenses' => [
                'expenses.view' => 'View expenses',
                'expenses.create' => 'Create expense',
                'expenses.edit' => 'Edit expense',
                'expenses.delete' => 'Delete expense',
                'expenses.export' => 'Export expenses',
                // Finance Phase 4 — split the finance side of expenses
                // from the basic CRUD slugs above.
                'expenses.assign_cashbox' => 'Assign cashbox / payment method to an expense',
                'expenses.post_to_cashbox' => 'Post an expense to a cashbox (retroactive)',
            ],
            // Finance Phase 1 — cashboxes foundation. Later phases extend
            // this module with: payment_methods, cashbox_transfers, refunds.
            'cashboxes' => [
                'cashboxes.view' => 'View cashboxes and balances',
                'cashboxes.create' => 'Create cashbox',
                'cashboxes.edit' => 'Edit cashbox',
                'cashboxes.deactivate' => 'Deactivate / reactivate cashbox',
                'cashbox_transactions.view' => 'View cashbox statement',
                'cashbox_transactions.create' => 'Record manual cashbox adjustment',
            ],
            // Finance Phase 2 — payment methods + cashbox transfers.
            'payment_methods' => [
                'payment_methods.view' => 'View payment methods',
                'payment_methods.create' => 'Create payment method',
                'payment_methods.edit' => 'Edit payment method',
                'payment_methods.deactivate' => 'Deactivate / reactivate payment method',
            ],
            'cashbox_transfers' => [
                'cashbox_transfers.view' => 'View cashbox transfers',
                'cashbox_transfers.create' => 'Record cashbox transfer',
            ],
            'ads' => [
                'ads.view' => 'View ads campaigns',
                'ads.create' => 'Create ads campaign',
                'ads.edit' => 'Edit ads campaign',
                'ads.delete' => 'Delete ads campaign',
            ],
            'marketers' => [
                'marketers.view' => 'View marketers',
                'marketers.create' => 'Create marketer',
                'marketers.edit' => 'Edit marketer',
                'marketers.wallet' => 'View marketer wallet',
                'marketers.statement' => 'View marketer statement',
                'marketers.prices' => 'Manage marketer prices',
            ],
            // Finance Phase 5D — marketer payout workflow (request →
            // approve/reject → pay). Separate slug group so the
            // separation of duties matrix can grant `pay` only to the
            // accountant role and keep `approve` with management.
            'marketer_payouts' => [
                'marketer_payouts.view' => 'View marketer payouts',
                'marketer_payouts.create' => 'Request marketer payout',
                'marketer_payouts.approve' => 'Approve marketer payout',
                'marketer_payouts.reject' => 'Reject marketer payout',
                'marketer_payouts.pay' => 'Pay marketer payout from cashbox',
            ],
            // Phase 7 — internal-support / customer-issue tickets.
            'tickets' => [
                'tickets.view' => 'View tickets',
                'tickets.create' => 'Create ticket',
                'tickets.edit' => 'Edit ticket',
                'tickets.delete' => 'Delete ticket',
                'tickets.manage' => 'Manage all tickets (cross-user access + status control)',
            ],
            'reports' => [
                'reports.view' => 'View reports module',
                'reports.sales' => 'View sales report',
                'reports.profit' => 'View profit report',
                'reports.cash_flow' => 'View cash flow report',
                'reports.marketers' => 'View marketers report',
                'reports.shipping' => 'View shipping report',
                'reports.inventory' => 'View inventory report',
                'reports.ads' => 'View ads report',
                'reports.staff' => 'View staff report',
            ],
            'system' => [
                'settings.manage' => 'Manage settings',
                'users.manage' => 'Manage users',
                'roles.manage' => 'Manage roles',
                'permissions.manage' => 'Manage permissions',
                'audit_logs.view' => 'View audit logs',
                'approvals.manage' => 'Manage approval requests',
                'notifications.manage' => 'Manage notifications',
                'year_end.manage' => 'Manage year-end closing',
                'backup.manage' => 'Manage backups',
            ],
        ];
    }
}
