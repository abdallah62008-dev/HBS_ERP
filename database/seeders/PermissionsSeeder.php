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
            ],
            'returns' => [
                'returns.view' => 'View returns',
                'returns.create' => 'Create return',
                'returns.approve' => 'Approve return',
                'returns.inspect' => 'Inspect returned product',
            ],
            'expenses' => [
                'expenses.view' => 'View expenses',
                'expenses.create' => 'Create expense',
                'expenses.edit' => 'Edit expense',
                'expenses.delete' => 'Delete expense',
                'expenses.export' => 'Export expenses',
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
