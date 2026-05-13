<?php

use App\Http\Controllers\AdCampaignsController;
use App\Http\Controllers\ApprovalsController;
use App\Http\Controllers\AttachmentsController;
use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\BackupsController;
use App\Http\Controllers\ExportsController;
use App\Http\Controllers\ImportExportController;
use App\Http\Controllers\ImportsController;
use App\Http\Controllers\YearEndClosingsController;
use App\Http\Controllers\CategoriesController;
use App\Http\Controllers\CollectionsController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseCategoriesController;
use App\Http\Controllers\CashboxesController;
use App\Http\Controllers\CashboxTransfersController;
use App\Http\Controllers\ExpensesController;
use App\Http\Controllers\PaymentMethodsController;
use App\Http\Controllers\RefundsController;
use App\Http\Controllers\FinancePeriodsController;
use App\Http\Controllers\FinanceReportsController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\MarketerPayoutsController;
use App\Http\Controllers\MarketerPortalController;
use App\Http\Controllers\MarketersController;
use App\Http\Controllers\MarketerStatementController;
use App\Http\Controllers\ModuleStubController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OrdersController;
use App\Http\Controllers\OrdersExportController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseInvoicesController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\ReturnReasonsController;
use App\Http\Controllers\ReturnsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShippingCompaniesController;
use App\Http\Controllers\ShippingController;
use App\Http\Controllers\ShippingLabelsController;
use App\Http\Controllers\StaffTargetsController;
use App\Http\Controllers\StockAdjustmentsController;
use App\Http\Controllers\StockCountsController;
use App\Http\Controllers\SuppliersController;
use App\Http\Controllers\WarehousesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('dashboard');
    }

    return redirect()->route('login');
});

/*
|--------------------------------------------------------------------------
| Authenticated app
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // Profile (Breeze)
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    /* ─────────────── Customers (Phase 2) ─────────────── */
    Route::middleware('permission:customers.view')->get('/customers/lookup', [CustomersController::class, 'lookupByPhone'])
        ->name('customers.lookup');

    Route::middleware('permission:customers.view')->get('/customers', [CustomersController::class, 'index'])
        ->name('customers.index');
    Route::middleware('permission:customers.create')->get('/customers/create', [CustomersController::class, 'create'])
        ->name('customers.create');
    Route::middleware('permission:customers.create')->post('/customers', [CustomersController::class, 'store'])
        ->name('customers.store');
    Route::middleware('permission:customers.view')->get('/customers/{customer}', [CustomersController::class, 'show'])
        ->name('customers.show');
    Route::middleware('permission:customers.edit')->get('/customers/{customer}/edit', [CustomersController::class, 'edit'])
        ->name('customers.edit');
    Route::middleware('permission:customers.edit')->put('/customers/{customer}', [CustomersController::class, 'update'])
        ->name('customers.update');
    Route::middleware('permission:customers.delete')->delete('/customers/{customer}', [CustomersController::class, 'destroy'])
        ->name('customers.destroy');

    /* ─────────────── Categories (Phase 2) ─────────────── */
    Route::middleware('permission:products.view')->get('/categories', [CategoriesController::class, 'index'])
        ->name('categories.index');
    Route::middleware('permission:products.create')->post('/categories', [CategoriesController::class, 'store'])
        ->name('categories.store');
    Route::middleware('permission:products.edit')->put('/categories/{category}', [CategoriesController::class, 'update'])
        ->name('categories.update');
    Route::middleware('permission:products.delete')->delete('/categories/{category}', [CategoriesController::class, 'destroy'])
        ->name('categories.destroy');

    /* ─────────────── Products (Phase 2) ─────────────── */
    Route::middleware('permission:products.view')->get('/products', [ProductsController::class, 'index'])
        ->name('products.index');
    Route::middleware('permission:products.create')->get('/products/create', [ProductsController::class, 'create'])
        ->name('products.create');
    Route::middleware('permission:products.create')->post('/products', [ProductsController::class, 'store'])
        ->name('products.store');
    Route::middleware('permission:products.view')->get('/products/{product}', [ProductsController::class, 'show'])
        ->name('products.show');
    Route::middleware('permission:products.edit')->get('/products/{product}/edit', [ProductsController::class, 'edit'])
        ->name('products.edit');
    Route::middleware('permission:products.edit')->put('/products/{product}', [ProductsController::class, 'update'])
        ->name('products.update');
    Route::middleware('permission:products.delete')->delete('/products/{product}', [ProductsController::class, 'destroy'])
        ->name('products.destroy');

    /* ─────────────── Orders (Phase 2) ─────────────── */
    // Export must come BEFORE /orders/{order} so the segment doesn't match.
    Route::middleware('permission:orders.export')->get('/orders/export', OrdersExportController::class)
        ->name('orders.export');

    Route::middleware('permission:orders.create')->post('/orders/check-duplicate', [OrdersController::class, 'checkDuplicate'])
        ->name('orders.check-duplicate');
    Route::middleware('permission:orders.create')->post('/orders/marketer-profit-preview', [OrdersController::class, 'marketerProfitPreview'])
        ->name('orders.marketer-profit-preview');

    Route::middleware('permission:orders.view')->get('/orders', [OrdersController::class, 'index'])
        ->name('orders.index');
    Route::middleware('permission:orders.create')->get('/orders/create', [OrdersController::class, 'create'])
        ->name('orders.create');
    Route::middleware('permission:orders.create')->post('/orders', [OrdersController::class, 'store'])
        ->name('orders.store');
    Route::middleware('permission:orders.view')->get('/orders/{order}', [OrdersController::class, 'show'])
        ->name('orders.show');
    Route::middleware('permission:orders.view')->get('/orders/{order}/timeline', [OrdersController::class, 'timeline'])
        ->name('orders.timeline');
    Route::middleware('permission:orders.edit')->get('/orders/{order}/edit', [OrdersController::class, 'edit'])
        ->name('orders.edit');
    Route::middleware(['permission:orders.edit', 'fiscal_year_lock'])->put('/orders/{order}', [OrdersController::class, 'update'])
        ->name('orders.update');
    Route::middleware(['permission:orders.change_status', 'fiscal_year_lock'])->post('/orders/{order}/status', [OrdersController::class, 'changeStatus'])
        ->name('orders.change-status');
    Route::middleware(['permission:orders.delete', 'fiscal_year_lock'])->delete('/orders/{order}', [OrdersController::class, 'destroy'])
        ->name('orders.destroy');

    /* ─────────────── Inventory (Phase 3) ─────────────── */
    Route::middleware('permission:inventory.view')->get('/inventory', [InventoryController::class, 'index'])
        ->name('inventory.index');
    Route::middleware('permission:inventory.view_movements')->get('/inventory/movements', [InventoryController::class, 'movements'])
        ->name('inventory.movements');
    Route::middleware('permission:inventory.view')->get('/inventory/low-stock', [InventoryController::class, 'lowStock'])
        ->name('inventory.low-stock');

    /* ─────────────── Warehouses (Phase 3) ─────────────── */
    Route::middleware('permission:inventory.view')->get('/warehouses', [WarehousesController::class, 'index'])
        ->name('warehouses.index');
    Route::middleware('permission:inventory.adjust')->post('/warehouses', [WarehousesController::class, 'store'])
        ->name('warehouses.store');
    Route::middleware('permission:inventory.adjust')->put('/warehouses/{warehouse}', [WarehousesController::class, 'update'])
        ->name('warehouses.update');
    Route::middleware('permission:inventory.adjust')->delete('/warehouses/{warehouse}', [WarehousesController::class, 'destroy'])
        ->name('warehouses.destroy');

    /* ─────────────── Stock Adjustments (Phase 3) ─────────────── */
    Route::middleware('permission:inventory.view_movements')->get('/stock-adjustments', [StockAdjustmentsController::class, 'index'])
        ->name('stock-adjustments.index');
    Route::middleware('permission:inventory.adjust')->get('/stock-adjustments/create', [StockAdjustmentsController::class, 'create'])
        ->name('stock-adjustments.create');
    Route::middleware('permission:inventory.adjust')->post('/stock-adjustments', [StockAdjustmentsController::class, 'store'])
        ->name('stock-adjustments.store');
    Route::middleware('permission:inventory.adjust')->post('/stock-adjustments/{stockAdjustment}/approve', [StockAdjustmentsController::class, 'approve'])
        ->name('stock-adjustments.approve');
    Route::middleware('permission:inventory.adjust')->post('/stock-adjustments/{stockAdjustment}/reject', [StockAdjustmentsController::class, 'reject'])
        ->name('stock-adjustments.reject');

    /* ─────────────── Stock Counts (Phase 3) ─────────────── */
    Route::middleware('permission:inventory.count')->get('/stock-counts', [StockCountsController::class, 'index'])
        ->name('stock-counts.index');
    Route::middleware('permission:inventory.count')->get('/stock-counts/create', [StockCountsController::class, 'create'])
        ->name('stock-counts.create');
    Route::middleware('permission:inventory.count')->post('/stock-counts', [StockCountsController::class, 'store'])
        ->name('stock-counts.store');
    Route::middleware('permission:inventory.count')->get('/stock-counts/{stockCount}', [StockCountsController::class, 'show'])
        ->name('stock-counts.show');
    Route::middleware('permission:inventory.count')->post('/stock-counts/{stockCount}/approve', [StockCountsController::class, 'approve'])
        ->name('stock-counts.approve');

    /* ─────────────── Suppliers (Phase 3) ─────────────── */
    Route::middleware('permission:suppliers.view')->get('/suppliers', [SuppliersController::class, 'index'])
        ->name('suppliers.index');
    Route::middleware('permission:suppliers.create')->get('/suppliers/create', [SuppliersController::class, 'create'])
        ->name('suppliers.create');
    Route::middleware('permission:suppliers.create')->post('/suppliers', [SuppliersController::class, 'store'])
        ->name('suppliers.store');
    Route::middleware('permission:suppliers.view')->get('/suppliers/{supplier}', [SuppliersController::class, 'show'])
        ->name('suppliers.show');
    Route::middleware('permission:suppliers.edit')->get('/suppliers/{supplier}/edit', [SuppliersController::class, 'edit'])
        ->name('suppliers.edit');
    Route::middleware('permission:suppliers.edit')->put('/suppliers/{supplier}', [SuppliersController::class, 'update'])
        ->name('suppliers.update');
    Route::middleware('permission:suppliers.edit')->delete('/suppliers/{supplier}', [SuppliersController::class, 'destroy'])
        ->name('suppliers.destroy');

    /* ─────────────── Purchase Invoices (Phase 3) ─────────────── */
    Route::middleware('permission:purchases.view')->get('/purchase-invoices', [PurchaseInvoicesController::class, 'index'])
        ->name('purchase-invoices.index');
    Route::middleware('permission:purchases.create')->get('/purchase-invoices/create', [PurchaseInvoicesController::class, 'create'])
        ->name('purchase-invoices.create');
    Route::middleware('permission:purchases.create')->post('/purchase-invoices', [PurchaseInvoicesController::class, 'store'])
        ->name('purchase-invoices.store');
    Route::middleware('permission:purchases.view')->get('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoicesController::class, 'show'])
        ->name('purchase-invoices.show');
    Route::middleware('permission:purchases.edit')->get('/purchase-invoices/{purchaseInvoice}/edit', [PurchaseInvoicesController::class, 'edit'])
        ->name('purchase-invoices.edit');
    Route::middleware(['permission:purchases.edit', 'fiscal_year_lock'])->put('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoicesController::class, 'update'])
        ->name('purchase-invoices.update');
    Route::middleware('permission:purchases.approve')->post('/purchase-invoices/{purchaseInvoice}/approve', [PurchaseInvoicesController::class, 'approve'])
        ->name('purchase-invoices.approve');
    Route::middleware('permission:purchases.edit')->post('/purchase-invoices/{purchaseInvoice}/payment', [PurchaseInvoicesController::class, 'recordPayment'])
        ->name('purchase-invoices.payment');
    Route::middleware('permission:purchases.delete')->delete('/purchase-invoices/{purchaseInvoice}', [PurchaseInvoicesController::class, 'destroy'])
        ->name('purchase-invoices.destroy');

    /* ─────────────── Phase 3 alias: /purchases keeps the sidebar happy ─────────────── */
    Route::middleware('permission:purchases.view')->get('/purchases', fn () => redirect()->route('purchase-invoices.index'))
        ->name('purchases.index');

    /* ─────────────── Shipping (Phase 4) ─────────────── */
    Route::middleware('permission:shipping.view')->get('/shipping', [ShippingController::class, 'dashboard'])
        ->name('shipping.dashboard');
    Route::middleware('permission:shipping.view')->get('/shipping/ready-to-pack', [ShippingController::class, 'readyToPack'])
        ->name('shipping.ready-to-pack');
    Route::middleware('permission:shipping.view')->get('/shipping/ready-to-ship', [ShippingController::class, 'readyToShip'])
        ->name('shipping.ready-to-ship');
    Route::middleware('permission:shipping.view')->get('/shipping/shipments', [ShippingController::class, 'shipments'])
        ->name('shipping.shipments');
    Route::middleware('permission:shipping.view')->get('/shipping/shipments/{shipment}', [ShippingController::class, 'showShipment'])
        ->name('shipping.shipments.show');
    Route::middleware('permission:shipping.view')->get('/shipping/delayed', [ShippingController::class, 'delayed'])
        ->name('shipping.delayed');
    Route::middleware('permission:shipping.view')->get('/shipping/checklist/{order}', [ShippingController::class, 'checklist'])
        ->name('shipping.checklist');

    // Worklist actions
    Route::middleware('permission:shipping.assign')->post('/shipping/assign/{order}', [ShippingController::class, 'assign'])
        ->name('shipping.assign');
    Route::middleware('permission:orders.change_status')->post('/shipping/mark-packed/{order}', [ShippingController::class, 'markPacked'])
        ->name('shipping.mark-packed');
    Route::middleware('permission:orders.change_status')->post('/shipping/confirm-shipped/{order}', [ShippingController::class, 'confirmShipped'])
        ->name('shipping.confirm-shipped');
    Route::middleware('permission:shipping.update_status')->post('/shipping/shipments/{shipment}/status', [ShippingController::class, 'markShipmentStatus'])
        ->name('shipping.shipments.mark-status');

    // Companies + rates
    Route::middleware('permission:shipping.view')->get('/shipping-companies', [ShippingCompaniesController::class, 'index'])
        ->name('shipping-companies.index');
    Route::middleware('permission:shipping.assign')->post('/shipping-companies', [ShippingCompaniesController::class, 'store'])
        ->name('shipping-companies.store');
    Route::middleware('permission:shipping.assign')->put('/shipping-companies/{shippingCompany}', [ShippingCompaniesController::class, 'update'])
        ->name('shipping-companies.update');
    Route::middleware('permission:shipping.assign')->delete('/shipping-companies/{shippingCompany}', [ShippingCompaniesController::class, 'destroy'])
        ->name('shipping-companies.destroy');
    Route::middleware('permission:shipping.view')->get('/shipping-companies/{shippingCompany}/rates', [ShippingCompaniesController::class, 'rates'])
        ->name('shipping-companies.rates');
    Route::middleware('permission:shipping.assign')->post('/shipping-companies/{shippingCompany}/rates', [ShippingCompaniesController::class, 'storeRate'])
        ->name('shipping-companies.rates.store');
    Route::middleware('permission:shipping.assign')->put('/shipping-companies/{shippingCompany}/rates/{rate}', [ShippingCompaniesController::class, 'updateRate'])
        ->name('shipping-companies.rates.update');
    Route::middleware('permission:shipping.assign')->delete('/shipping-companies/{shippingCompany}/rates/{rate}', [ShippingCompaniesController::class, 'destroyRate'])
        ->name('shipping-companies.rates.destroy');

    // Labels
    Route::middleware('permission:shipping.print_label')->get('/shipping-labels', [ShippingLabelsController::class, 'index'])
        ->name('shipping-labels.index');
    Route::middleware('permission:shipping.print_label')->get('/shipping-labels/{order}/print', [ShippingLabelsController::class, 'print'])
        ->name('shipping-labels.print');

    // Order attachments (used by the checklist photo uploader)
    Route::middleware('permission:orders.edit')->post('/orders/{order}/attachments', [AttachmentsController::class, 'uploadForOrder'])
        ->name('orders.attachments.store');
    Route::middleware('permission:orders.edit')->delete('/attachments/{attachment}', [AttachmentsController::class, 'destroy'])
        ->name('attachments.destroy');

    /* ─────────────── Collections (Phase 4) ─────────────── */
    Route::middleware('permission:collections.view')->get('/collections', [CollectionsController::class, 'index'])
        ->name('collections.index');
    Route::middleware('permission:collections.update')->put('/collections/{collection}', [CollectionsController::class, 'update'])
        ->name('collections.update');
    // Finance Phase 3 — post a collection's collected amount to a cashbox.
    // Separate permission so "edit collection fields" and "move money into
    // a cashbox" remain distinct authorities (Phase 0 design).
    Route::middleware('permission:collections.reconcile_settlement')
        ->post('/collections/{collection}/post-to-cashbox', [CollectionsController::class, 'postToCashbox'])
        ->name('collections.post-to-cashbox');

    /* ─────────────── Returns (Phase 4) ─────────────── */
    Route::middleware('permission:returns.view')->get('/returns', [ReturnsController::class, 'index'])
        ->name('returns.index');
    Route::middleware('permission:returns.create')->get('/returns/create', [ReturnsController::class, 'create'])
        ->name('returns.create');
    Route::middleware('permission:returns.create')->post('/returns', [ReturnsController::class, 'store'])
        ->name('returns.store');
    Route::middleware('permission:returns.view')->get('/returns/{return}', [ReturnsController::class, 'show'])
        ->name('returns.show');
    Route::middleware('permission:returns.inspect')->post('/returns/{return}/inspect', [ReturnsController::class, 'inspect'])
        ->name('returns.inspect');
    Route::middleware('permission:returns.approve')->post('/returns/{return}/close', [ReturnsController::class, 'close'])
        ->name('returns.close');
    // Finance Phase 5C — request a (paperwork-only) refund from an
    // inspected return. Reuses the existing `refunds.create`
    // permission per Phase 0 design — no new slug introduced.
    Route::middleware('permission:refunds.create')->post('/returns/{return}/request-refund', [ReturnsController::class, 'requestRefund'])
        ->name('returns.request-refund');

    Route::middleware('permission:returns.view')->get('/return-reasons', [ReturnReasonsController::class, 'index'])
        ->name('return-reasons.index');
    Route::middleware('permission:returns.approve')->post('/return-reasons', [ReturnReasonsController::class, 'store'])
        ->name('return-reasons.store');
    Route::middleware('permission:returns.approve')->put('/return-reasons/{returnReason}', [ReturnReasonsController::class, 'update'])
        ->name('return-reasons.update');
    Route::middleware('permission:returns.approve')->delete('/return-reasons/{returnReason}', [ReturnReasonsController::class, 'destroy'])
        ->name('return-reasons.destroy');

    /* ─────────────── Tickets (Phase 7) ─────────────── */
    Route::middleware('permission:tickets.view')->get('/tickets', [\App\Http\Controllers\TicketsController::class, 'index'])->name('tickets.index');
    Route::middleware('permission:tickets.create')->get('/tickets/create', [\App\Http\Controllers\TicketsController::class, 'create'])->name('tickets.create');
    Route::middleware('permission:tickets.create')->post('/tickets', [\App\Http\Controllers\TicketsController::class, 'store'])->name('tickets.store');
    Route::middleware('permission:tickets.view')->get('/tickets/{ticket}', [\App\Http\Controllers\TicketsController::class, 'show'])->name('tickets.show');
    Route::middleware('permission:tickets.edit')->get('/tickets/{ticket}/edit', [\App\Http\Controllers\TicketsController::class, 'edit'])->name('tickets.edit');
    Route::middleware('permission:tickets.edit')->put('/tickets/{ticket}', [\App\Http\Controllers\TicketsController::class, 'update'])->name('tickets.update');
    Route::middleware('permission:tickets.delete')->delete('/tickets/{ticket}', [\App\Http\Controllers\TicketsController::class, 'destroy'])->name('tickets.destroy');

    /* ─────────────── Expenses (Phase 5) ─────────────── */
    Route::middleware('permission:expenses.view')->get('/expenses', [ExpensesController::class, 'index'])->name('expenses.index');
    Route::middleware('permission:expenses.create')->get('/expenses/create', [ExpensesController::class, 'create'])->name('expenses.create');
    Route::middleware('permission:expenses.create')->post('/expenses', [ExpensesController::class, 'store'])->name('expenses.store');
    Route::middleware('permission:expenses.edit')->get('/expenses/{expense}/edit', [ExpensesController::class, 'edit'])->name('expenses.edit');
    Route::middleware(['permission:expenses.edit', 'fiscal_year_lock'])->put('/expenses/{expense}', [ExpensesController::class, 'update'])->name('expenses.update');
    Route::middleware(['permission:expenses.delete', 'fiscal_year_lock'])->delete('/expenses/{expense}', [ExpensesController::class, 'destroy'])->name('expenses.destroy');
    // Finance Phase 4 — retroactively post a historical / null-cashbox
    // expense to a cashbox. Separate permission from `expenses.edit`.
    Route::middleware('permission:expenses.post_to_cashbox')
        ->post('/expenses/{expense}/post-to-cashbox', [ExpensesController::class, 'postToCashbox'])
        ->name('expenses.post-to-cashbox');

    /* ─────────────── Cashboxes (Finance Phase 1) ─────────────── */
    Route::middleware('permission:cashboxes.view')->get('/cashboxes', [CashboxesController::class, 'index'])->name('cashboxes.index');
    Route::middleware('permission:cashboxes.create')->get('/cashboxes/create', [CashboxesController::class, 'create'])->name('cashboxes.create');
    Route::middleware('permission:cashboxes.create')->post('/cashboxes', [CashboxesController::class, 'store'])->name('cashboxes.store');
    Route::middleware('permission:cashboxes.view,cashbox_transactions.view')->get('/cashboxes/{cashbox}', [CashboxesController::class, 'show'])->name('cashboxes.show');
    Route::middleware('permission:cashboxes.edit')->get('/cashboxes/{cashbox}/edit', [CashboxesController::class, 'edit'])->name('cashboxes.edit');
    Route::middleware('permission:cashboxes.edit')->put('/cashboxes/{cashbox}', [CashboxesController::class, 'update'])->name('cashboxes.update');
    Route::middleware('permission:cashboxes.deactivate')->post('/cashboxes/{cashbox}/deactivate', [CashboxesController::class, 'deactivate'])->name('cashboxes.deactivate');
    Route::middleware('permission:cashboxes.deactivate')->post('/cashboxes/{cashbox}/reactivate', [CashboxesController::class, 'reactivate'])->name('cashboxes.reactivate');
    Route::middleware('permission:cashbox_transactions.create')->post('/cashboxes/{cashbox}/transactions', [CashboxesController::class, 'storeTransaction'])->name('cashboxes.transactions.store');

    /* ─────────────── Payment methods (Finance Phase 2) ─────────────── */
    Route::middleware('permission:payment_methods.view')->get('/payment-methods', [PaymentMethodsController::class, 'index'])->name('payment-methods.index');
    Route::middleware('permission:payment_methods.create')->get('/payment-methods/create', [PaymentMethodsController::class, 'create'])->name('payment-methods.create');
    Route::middleware('permission:payment_methods.create')->post('/payment-methods', [PaymentMethodsController::class, 'store'])->name('payment-methods.store');
    Route::middleware('permission:payment_methods.edit')->get('/payment-methods/{paymentMethod}/edit', [PaymentMethodsController::class, 'edit'])->name('payment-methods.edit');
    Route::middleware('permission:payment_methods.edit')->put('/payment-methods/{paymentMethod}', [PaymentMethodsController::class, 'update'])->name('payment-methods.update');
    Route::middleware('permission:payment_methods.deactivate')->post('/payment-methods/{paymentMethod}/deactivate', [PaymentMethodsController::class, 'deactivate'])->name('payment-methods.deactivate');
    Route::middleware('permission:payment_methods.deactivate')->post('/payment-methods/{paymentMethod}/reactivate', [PaymentMethodsController::class, 'reactivate'])->name('payment-methods.reactivate');

    /* ─────────────── Cashbox transfers (Finance Phase 2) ─────────────── */
    Route::middleware('permission:cashbox_transfers.view')->get('/cashbox-transfers', [CashboxTransfersController::class, 'index'])->name('cashbox-transfers.index');
    Route::middleware('permission:cashbox_transfers.create')->get('/cashbox-transfers/create', [CashboxTransfersController::class, 'create'])->name('cashbox-transfers.create');
    Route::middleware('permission:cashbox_transfers.create')->post('/cashbox-transfers', [CashboxTransfersController::class, 'store'])->name('cashbox-transfers.store');

    /* ─────────────── Refunds (Finance Phase 5A — Foundation) ───────────────
     *
     * Lifecycle: requested → approved | rejected. No `pay` action yet —
     * the cashbox OUT transaction is Phase 5B's job.
     */
    Route::middleware('permission:refunds.view')->get('/refunds', [RefundsController::class, 'index'])->name('refunds.index');
    Route::middleware('permission:refunds.create')->get('/refunds/create', [RefundsController::class, 'create'])->name('refunds.create');
    Route::middleware('permission:refunds.create')->post('/refunds', [RefundsController::class, 'store'])->name('refunds.store');
    Route::middleware('permission:refunds.create')->get('/refunds/{refund}/edit', [RefundsController::class, 'edit'])->name('refunds.edit');
    Route::middleware('permission:refunds.create')->put('/refunds/{refund}', [RefundsController::class, 'update'])->name('refunds.update');
    Route::middleware('permission:refunds.create')->delete('/refunds/{refund}', [RefundsController::class, 'destroy'])->name('refunds.destroy');
    Route::middleware('permission:refunds.approve')->post('/refunds/{refund}/approve', [RefundsController::class, 'approve'])->name('refunds.approve');
    Route::middleware('permission:refunds.reject')->post('/refunds/{refund}/reject', [RefundsController::class, 'reject'])->name('refunds.reject');
    // Finance Phase 5B — pay an approved refund from a cashbox. Writes
    // the OUT transaction; separate permission so request / approve /
    // pay remain three distinct authorities (separation of duties).
    Route::middleware('permission:refunds.pay')->post('/refunds/{refund}/pay', [RefundsController::class, 'pay'])->name('refunds.pay');

    Route::middleware('permission:expenses.view')->get('/expense-categories', [ExpenseCategoriesController::class, 'index'])->name('expense-categories.index');
    Route::middleware('permission:expenses.edit')->post('/expense-categories', [ExpenseCategoriesController::class, 'store'])->name('expense-categories.store');
    Route::middleware('permission:expenses.edit')->put('/expense-categories/{expenseCategory}', [ExpenseCategoriesController::class, 'update'])->name('expense-categories.update');
    Route::middleware('permission:expenses.edit')->delete('/expense-categories/{expenseCategory}', [ExpenseCategoriesController::class, 'destroy'])->name('expense-categories.destroy');

    /* ─────────────── Ad campaigns (Phase 5) ─────────────── */
    // Use {ad} as the route parameter to avoid Laravel's binding looking up an "AdCampaign" model from "{adCampaign}".
    Route::middleware('permission:ads.view')->get('/ads', [AdCampaignsController::class, 'index'])->name('ads.index');
    Route::middleware('permission:ads.create')->get('/ads/create', [AdCampaignsController::class, 'create'])->name('ads.create');
    Route::middleware('permission:ads.create')->post('/ads', [AdCampaignsController::class, 'store'])->name('ads.store');
    Route::middleware('permission:ads.view')->get('/ads/{ad}', [AdCampaignsController::class, 'show'])->name('ads.show');
    Route::middleware('permission:ads.edit')->get('/ads/{ad}/edit', [AdCampaignsController::class, 'edit'])->name('ads.edit');
    Route::middleware('permission:ads.edit')->put('/ads/{ad}', [AdCampaignsController::class, 'update'])->name('ads.update');
    Route::middleware('permission:ads.edit')->post('/ads/{ad}/rollup', [AdCampaignsController::class, 'rollup'])->name('ads.rollup');
    Route::middleware('permission:ads.delete')->delete('/ads/{ad}', [AdCampaignsController::class, 'destroy'])->name('ads.destroy');

    /* ─────────────── Marketers (Phase 5) ─────────────── */
    Route::middleware('permission:marketers.view')->get('/marketers', [MarketersController::class, 'index'])->name('marketers.index');
    Route::middleware('permission:marketers.create')->get('/marketers/create', [MarketersController::class, 'create'])->name('marketers.create');
    Route::middleware('permission:marketers.create')->post('/marketers', [MarketersController::class, 'store'])->name('marketers.store');
    Route::middleware('permission:marketers.view')->get('/marketers/{marketer}', [MarketersController::class, 'show'])->name('marketers.show');
    Route::middleware('permission:marketers.edit')->get('/marketers/{marketer}/edit', [MarketersController::class, 'edit'])->name('marketers.edit');
    Route::middleware('permission:marketers.edit')->put('/marketers/{marketer}', [MarketersController::class, 'update'])->name('marketers.update');
    Route::middleware('permission:marketers.wallet')->get('/marketers/{marketer}/wallet', [MarketersController::class, 'wallet'])->name('marketers.wallet');
    Route::middleware('permission:marketers.wallet')->post('/marketers/{marketer}/payout', [MarketersController::class, 'payout'])->name('marketers.payout');
    Route::middleware('permission:marketers.wallet')->post('/marketers/{marketer}/adjust', [MarketersController::class, 'adjust'])->name('marketers.adjust');
    Route::middleware('permission:marketers.statement')->get('/marketers/{marketer}/statement', [MarketerStatementController::class, 'exportAdmin'])->name('marketers.statement');
    Route::middleware('permission:marketers.prices')->get('/marketers/{marketer}/prices', [MarketersController::class, 'prices'])->name('marketers.prices');
    Route::middleware('permission:marketers.prices')->post('/marketers/{marketer}/prices', [MarketersController::class, 'storePrice'])->name('marketers.prices.store');
    Route::middleware('permission:marketers.prices')->delete('/marketers/{marketer}/prices/{price}', [MarketersController::class, 'destroyPrice'])->name('marketers.prices.destroy');

    /* ─────────────── Marketer Payouts (Finance Phase 5D) ─────────────── */
    Route::middleware('permission:marketer_payouts.view')->get('/marketer-payouts', [MarketerPayoutsController::class, 'index'])->name('marketer-payouts.index');
    Route::middleware('permission:marketer_payouts.create')->get('/marketer-payouts/create', [MarketerPayoutsController::class, 'create'])->name('marketer-payouts.create');
    Route::middleware('permission:marketer_payouts.create')->post('/marketer-payouts', [MarketerPayoutsController::class, 'store'])->name('marketer-payouts.store');
    Route::middleware('permission:marketer_payouts.create')->get('/marketer-payouts/{payout}/edit', [MarketerPayoutsController::class, 'edit'])->name('marketer-payouts.edit');
    Route::middleware('permission:marketer_payouts.create')->put('/marketer-payouts/{payout}', [MarketerPayoutsController::class, 'update'])->name('marketer-payouts.update');
    Route::middleware('permission:marketer_payouts.create')->delete('/marketer-payouts/{payout}', [MarketerPayoutsController::class, 'destroy'])->name('marketer-payouts.destroy');
    Route::middleware('permission:marketer_payouts.approve')->post('/marketer-payouts/{payout}/approve', [MarketerPayoutsController::class, 'approve'])->name('marketer-payouts.approve');
    Route::middleware('permission:marketer_payouts.reject')->post('/marketer-payouts/{payout}/reject', [MarketerPayoutsController::class, 'reject'])->name('marketer-payouts.reject');
    Route::middleware('permission:marketer_payouts.pay')->post('/marketer-payouts/{payout}/pay', [MarketerPayoutsController::class, 'pay'])->name('marketer-payouts.pay');

    /* ─────────────── Staff (Phase 6) ─────────────── */
    Route::middleware('permission:users.manage')->get('/staff', fn () => redirect()->route('staff-targets.index'))->name('staff.index');
    Route::middleware('permission:users.manage')->get('/staff/targets', [StaffTargetsController::class, 'index'])->name('staff-targets.index');
    Route::middleware('permission:users.manage')->post('/staff/targets', [StaffTargetsController::class, 'store'])->name('staff-targets.store');
    Route::middleware('permission:users.manage')->put('/staff/targets/{staffTarget}', [StaffTargetsController::class, 'update'])->name('staff-targets.update');
    Route::middleware('permission:users.manage')->delete('/staff/targets/{staffTarget}', [StaffTargetsController::class, 'destroy'])->name('staff-targets.destroy');

    /* ─────────────── Reports (Phase 6) ─────────────── */
    Route::middleware('permission:reports.view')->prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportsController::class, 'index'])->name('index');
        Route::middleware('permission:reports.sales')->get('/sales', [ReportsController::class, 'sales'])->name('sales');
        Route::middleware('permission:reports.profit')->get('/profit', [ReportsController::class, 'profit'])->name('profit');
        Route::middleware('permission:reports.profit')->get('/product-profitability', [ReportsController::class, 'productProfitability'])->name('product-profitability');
        Route::middleware('permission:reports.profit')->get('/unprofitable-products', [ReportsController::class, 'unprofitableProducts'])->name('unprofitable-products');
        Route::middleware('permission:reports.inventory')->get('/inventory', [ReportsController::class, 'inventory'])->name('inventory');
        Route::middleware('permission:reports.inventory')->get('/stock-forecast', [ReportsController::class, 'stockForecast'])->name('stock-forecast');
        Route::middleware('permission:reports.shipping')->get('/shipping', [ReportsController::class, 'shipping'])->name('shipping');
        Route::middleware('permission:reports.cash_flow')->get('/collections', [ReportsController::class, 'collections'])->name('collections');
        Route::middleware('permission:reports.profit')->get('/returns', [ReportsController::class, 'returns'])->name('returns');
        Route::middleware('permission:reports.marketers')->get('/marketers', [ReportsController::class, 'marketers'])->name('marketers');
        Route::middleware('permission:reports.staff')->get('/staff', [ReportsController::class, 'staff'])->name('staff');
        Route::middleware('permission:reports.ads')->get('/ads', [ReportsController::class, 'ads'])->name('ads');
        Route::middleware('permission:reports.cash_flow')->get('/cash-flow', [ReportsController::class, 'cashFlow'])->name('cash-flow');
    });

    /* ─────────────── Finance Periods (Phase 5F) ─────────────── */
    Route::middleware('permission:finance_periods.view')->get('/finance/periods', [FinancePeriodsController::class, 'index'])->name('finance-periods.index');
    Route::middleware('permission:finance_periods.create')->get('/finance/periods/create', [FinancePeriodsController::class, 'create'])->name('finance-periods.create');
    Route::middleware('permission:finance_periods.create')->post('/finance/periods', [FinancePeriodsController::class, 'store'])->name('finance-periods.store');
    Route::middleware('permission:finance_periods.update')->get('/finance/periods/{period}/edit', [FinancePeriodsController::class, 'edit'])->name('finance-periods.edit');
    Route::middleware('permission:finance_periods.update')->put('/finance/periods/{period}', [FinancePeriodsController::class, 'update'])->name('finance-periods.update');
    Route::middleware('permission:finance_periods.close')->post('/finance/periods/{period}/close', [FinancePeriodsController::class, 'close'])->name('finance-periods.close');
    Route::middleware('permission:finance_periods.reopen')->post('/finance/periods/{period}/reopen', [FinancePeriodsController::class, 'reopen'])->name('finance-periods.reopen');

    /* ─────────────── Finance Reports (Phase 5E) ─────────────── */
    Route::middleware('permission:finance_reports.view')->prefix('finance/reports')->name('finance-reports.')->group(function () {
        Route::get('/', [FinanceReportsController::class, 'index'])->name('index');
        Route::get('/cashboxes', [FinanceReportsController::class, 'cashboxes'])->name('cashboxes');
        Route::get('/movements', [FinanceReportsController::class, 'movements'])->name('movements');
        Route::get('/collections', [FinanceReportsController::class, 'collections'])->name('collections');
        Route::get('/expenses', [FinanceReportsController::class, 'expenses'])->name('expenses');
        Route::get('/refunds', [FinanceReportsController::class, 'refunds'])->name('refunds');
        Route::get('/marketer-payouts', [FinanceReportsController::class, 'marketerPayouts'])->name('marketer-payouts');
        Route::get('/transfers', [FinanceReportsController::class, 'transfers'])->name('transfers');
        Route::get('/cash-flow', [FinanceReportsController::class, 'cashFlow'])->name('cash-flow');
    });

    /* ─────────────── Notifications (Phase 6) ─────────────── */
    Route::get('/notifications', [NotificationsController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/summary', [NotificationsController::class, 'summary'])->name('notifications.summary');
    Route::post('/notifications/{notification}/read', [NotificationsController::class, 'markRead'])->name('notifications.mark-read');
    Route::post('/notifications/mark-all-read', [NotificationsController::class, 'markAllRead'])->name('notifications.mark-all-read');
    Route::middleware('permission:notifications.manage')->post('/notifications/refresh', [NotificationsController::class, 'refresh'])->name('notifications.refresh');

    /* ─────────────── Users + Roles admin (Phase 6.1) ─────────────── */
    Route::middleware('permission:users.manage')->get('/users', [\App\Http\Controllers\UsersController::class, 'index'])->name('users.index');
    Route::middleware('permission:users.manage')->get('/users/create', [\App\Http\Controllers\UsersController::class, 'create'])->name('users.create');
    Route::middleware('permission:users.manage')->post('/users', [\App\Http\Controllers\UsersController::class, 'store'])->name('users.store');
    Route::middleware('permission:users.manage')->get('/users/{user}/edit', [\App\Http\Controllers\UsersController::class, 'edit'])->name('users.edit');
    Route::middleware('permission:users.manage')->put('/users/{user}', [\App\Http\Controllers\UsersController::class, 'update'])->name('users.update');
    Route::middleware('permission:users.manage')->put('/users/{user}/permissions', [\App\Http\Controllers\UsersController::class, 'syncOverrides'])->name('users.permissions.sync');
    Route::middleware('permission:users.manage')->delete('/users/{user}', [\App\Http\Controllers\UsersController::class, 'destroy'])->name('users.destroy');

    Route::middleware('permission:roles.manage')->get('/roles', [\App\Http\Controllers\RolesController::class, 'index'])->name('roles.index');
    Route::middleware('permission:roles.manage')->get('/roles/{role}/edit', [\App\Http\Controllers\RolesController::class, 'edit'])->name('roles.edit');
    Route::middleware('permission:roles.manage')->put('/roles/{role}', [\App\Http\Controllers\RolesController::class, 'update'])->name('roles.update');

    /* ─────────────── Audit logs viewer (Phase 6) ─────────────── */
    Route::middleware('permission:audit_logs.view')->get('/audit-logs', [AuditLogsController::class, 'index'])->name('audit-logs.index');
    Route::middleware('permission:audit_logs.view')->get('/audit-logs/{log}', [AuditLogsController::class, 'show'])->name('audit-logs.show');

    /* ─────────────── Settings (Phase 6) ─────────────── */
    Route::middleware('permission:settings.manage')->get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::middleware('permission:settings.manage')->put('/settings', [SettingsController::class, 'update'])->name('settings.update');

    /* ─────────────── Import / Export Center (Phase 7) ─────────────── */
    Route::middleware('permission:orders.import,orders.export,products.import,products.export,expenses.export')
        ->get('/import-export', [ImportExportController::class, 'index'])->name('import-export.index');

    // Imports
    Route::middleware('permission:products.import,orders.import')->get('/imports', [ImportsController::class, 'index'])->name('imports.index');
    Route::middleware('permission:products.import,orders.import')->get('/imports/create', [ImportsController::class, 'create'])->name('imports.create');
    Route::middleware('permission:products.import,orders.import')->post('/imports/upload', [ImportsController::class, 'upload'])->name('imports.upload');
    Route::middleware('permission:products.import,orders.import')->get('/imports/{import}', [ImportsController::class, 'show'])->name('imports.show');
    Route::middleware('permission:products.import,orders.import')->post('/imports/{import}/commit', [ImportsController::class, 'commit'])->name('imports.commit');
    Route::middleware('permission:products.import,orders.import')->post('/imports/{import}/undo', [ImportsController::class, 'undo'])->name('imports.undo');

    // Exports
    Route::middleware('permission:orders.export,products.export,expenses.export')->get('/exports', [ExportsController::class, 'index'])->name('exports.index');
    Route::middleware('permission:orders.export,products.export,expenses.export')->get('/exports/download', [ExportsController::class, 'download'])->name('exports.download');

    /* ─────────────── Approvals (Phase 8) ─────────────── */
    Route::middleware('permission:approvals.manage')->get('/approvals', [ApprovalsController::class, 'index'])->name('approvals.index');
    Route::middleware('permission:approvals.manage')->get('/approvals/{approval}', [ApprovalsController::class, 'show'])->name('approvals.show');
    Route::middleware('permission:approvals.manage')->post('/approvals/{approval}/approve', [ApprovalsController::class, 'approve'])->name('approvals.approve');
    Route::middleware('permission:approvals.manage')->post('/approvals/{approval}/reject', [ApprovalsController::class, 'reject'])->name('approvals.reject');

    // Approval-gated actions invoked from other pages.
    Route::middleware(['permission:orders.edit', 'fiscal_year_lock'])->post('/orders/{order}/request-price-edit', [OrdersController::class, 'requestPriceEdit'])->name('orders.request-price-edit');

    /* ─────────────── Backups (Phase 8) ─────────────── */
    Route::middleware('permission:backup.manage')->get('/backups', [BackupsController::class, 'index'])->name('backups.index');
    Route::middleware('permission:backup.manage')->post('/backups/run', [BackupsController::class, 'run'])->name('backups.run');

    /* ─────────────── Year-end closing (Phase 8) ─────────────── */
    Route::middleware('permission:year_end.manage')->get('/year-end', [YearEndClosingsController::class, 'index'])->name('year-end.index');
    Route::middleware('permission:year_end.manage')->get('/year-end/{fiscalYear}/review', [YearEndClosingsController::class, 'review'])->name('year-end.review');
    Route::middleware('permission:year_end.manage')->post('/year-end/{fiscalYear}/close', [YearEndClosingsController::class, 'close'])->name('year-end.close');

    /* ─────────────── Marketer self-service portal (Phase 5) ─────────────── */
    // Gated by role:marketer (super-admin can also access for support/debug).
    Route::middleware('role:marketer,super-admin')->prefix('marketer')->name('marketer.')->group(function () {
        Route::get('/dashboard', [MarketerPortalController::class, 'dashboard'])->name('dashboard');
        Route::get('/orders', [MarketerPortalController::class, 'orders'])->name('orders');
        Route::get('/orders/{order}', [MarketerPortalController::class, 'showOrder'])->name('orders.show');
        Route::get('/wallet', [MarketerPortalController::class, 'wallet'])->name('wallet');
        Route::get('/statement', [MarketerPortalController::class, 'statement'])->name('statement');
        Route::get('/statement.xlsx', [MarketerStatementController::class, 'exportSelf'])->name('statement.export');
        Route::get('/products', [MarketerPortalController::class, 'products'])->name('products');
    });
});

require __DIR__.'/auth.php';
