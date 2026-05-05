/**
 * Sidebar layout for the Hnawbas Operations System.
 *
 * Two structures are exported:
 *   - sidebarSections      → admin / staff layout (collapsible groups)
 *   - marketerSidebarSections → simplified marketer-portal layout
 *
 * SidebarNav.jsx picks one based on `auth.user.is_marketer` (super-admins
 * always see the admin layout).
 *
 * Item shape:
 *   - label:      display text
 *   - href:       target URL (only routes that actually exist server-side)
 *   - icon:       heroicons-outline path data
 *   - permission: a single slug OR an array of slugs (visible if user has ANY)
 *                 — leave undefined to always show
 *
 * Group shape:
 *   - title: display text (top divider/header)
 *   - icon:  optional group icon (rendered next to the title)
 *   - items: array of items
 *
 * Visibility rules enforced by the renderer (SidebarNav.jsx):
 *   1. Item without `permission` is always shown.
 *   2. Item with string `permission` shows when user has that slug.
 *   3. Item with array `permission` shows when user has ANY one of them.
 *   4. A group with zero visible items is hidden entirely.
 *
 * NOTE: the proposed sidebar in the brief listed several items whose
 * routes do not exist yet (Refunds, Customer Notes, Complaints, Roles
 * & Permissions, Users, Fiscal Years management, Marketer Wallets/
 * Settlements/Reports as aggregate views, Campaigns vs. Ads, Campaign
 * Performance). Per "If a route does not exist, hide the item" we omit
 * those items from this config rather than render dead links.
 */

const I = {
    // Heroicons v2 outline path data
    home: 'M2.25 12 12 2.25 21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-6h4.5v6h4.125c.621 0 1.125-.504 1.125-1.125V9.75',
    cart: 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 0 0-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 0 0-16.536-1.84M7.5 14.25 5.106 5.272M6 20.25a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Zm12.75 0a.75.75 0 1 1-1.5 0 .75.75 0 0 1 1.5 0Z',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    users: 'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
    box: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    layers: 'M6.429 9.75 2.25 12l4.179 2.25m0-4.5 5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0 4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0-5.571 3-5.571-3',
    warning: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z',
    truck: 'M8.25 18.75a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m3 0H15M5.25 18.75a1.5 1.5 0 0 1-3 0V8.25h12v10.5a1.5 1.5 0 0 1-1.5 1.5h-2.25M16.5 12h2.25c.621 0 1.125.504 1.125 1.125v3.375M16.5 12V8.25M2.25 5.25h12c.621 0 1.125.504 1.125 1.125V12',
    cash: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.485 0-4.5-1.79-4.5-4 0-2.21 2.015-4 4.5-4s4.5 1.79 4.5 4M3 12a9 9 0 1 1 18 0 9 9 0 0 1-18 0Z',
    revert: 'M9 15 3 9m0 0 6-6M3 9h12a6 6 0 0 1 0 12h-3',
    ticket: 'M16.5 6v.75c0 .414.336.75.75.75h2.625c.621 0 1.125.504 1.125 1.125V18.75M16.5 6V4.875c0-.621-.504-1.125-1.125-1.125H8.625c-.621 0-1.125.504-1.125 1.125V6m9 0H7.5m9 0h.375c.621 0 1.125.504 1.125 1.125v6.75c0 .621-.504 1.125-1.125 1.125h-.375M7.5 6V4.875C7.5 4.254 6.996 3.75 6.375 3.75h-1.5C4.254 3.75 3.75 4.254 3.75 4.875V18.75c0 .621.504 1.125 1.125 1.125h1.5c.621 0 1.125-.504 1.125-1.125V6Z',
    receipt: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z',
    megaphone: 'M10.34 15.84c-.688-.06-1.386-.09-2.09-.09H7.5a4.5 4.5 0 1 1 0-9h.75c.704 0 1.402-.03 2.09-.09m0 9.18c.253.962.584 1.892.985 2.783.247.55.06 1.21-.463 1.511l-.657.38c-.551.318-1.26.117-1.527-.461a20.845 20.845 0 0 1-1.44-4.282m3.102.069a18.03 18.03 0 0 1-.59-4.59c0-1.586.205-3.124.59-4.59m0 9.18a23.848 23.848 0 0 1 8.835 2.535M10.34 6.66a23.847 23.847 0 0 0 8.835-2.535m0 0A23.74 23.74 0 0 0 18.795 3m.38 1.125a23.91 23.91 0 0 1 1.014 5.395m-1.014 8.855c-.118.38-.245.754-.38 1.125m.38-1.125a23.91 23.91 0 0 0 1.014-5.395m0-3.46c.495.413.811 1.035.811 1.73 0 .695-.316 1.317-.811 1.73m0-3.46a24.347 24.347 0 0 1 0 3.46',
    chartPie: 'M10.5 6a7.5 7.5 0 1 0 7.5 7.5h-7.5V6Z M13.5 10.5H21A7.5 7.5 0 0 0 13.5 3v7.5Z',
    person: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
    target: 'M9 12.75 11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 0 1-1.043 3.296 3.745 3.745 0 0 1-3.296 1.043A3.745 3.745 0 0 1 12 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 0 1-3.296-1.043 3.745 3.745 0 0 1-1.043-3.296A3.745 3.745 0 0 1 3 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 0 1 1.043-3.296 3.746 3.746 0 0 1 3.296-1.043A3.746 3.746 0 0 1 12 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 0 1 3.296 1.043 3.746 3.746 0 0 1 1.043 3.296A3.745 3.745 0 0 1 21 12Z',
    download: 'M3 16.5v2.25A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75V16.5M16.5 12 12 16.5m0 0L7.5 12m4.5 4.5V3',
    check: 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z',
    bell: 'M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0',
    log: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25Z',
    cog: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.325.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 0 1 1.37.49l1.296 2.247a1.125 1.125 0 0 1-.26 1.431l-1.003.827c-.293.241-.438.613-.43.992a7.723 7.723 0 0 1 0 .255c-.008.378.137.75.43.991l1.004.827c.424.35.534.955.26 1.43l-1.298 2.247a1.125 1.125 0 0 1-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.47 6.47 0 0 1-.22.128c-.331.183-.581.495-.644.869l-.213 1.281c-.09.543-.56.94-1.11.94h-2.594c-.55 0-1.019-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 0 1-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 0 1-1.369-.49l-1.297-2.247a1.125 1.125 0 0 1 .26-1.431l1.004-.827c.292-.24.437-.613.43-.991a6.932 6.932 0 0 1 0-.255c.007-.38-.138-.751-.43-.992l-1.004-.827a1.125 1.125 0 0 1-.26-1.43l1.297-2.247a1.125 1.125 0 0 1 1.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.086.22-.128.332-.183.582-.495.644-.869l.214-1.28Z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    archive: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
    closeYear: 'M9 12.75 11.25 15 15 9.75M21.75 12a9.75 9.75 0 1 1-19.5 0 9.75 9.75 0 0 1 19.5 0Z',
};

/* Top-level "Dashboard" item appears outside any group (always shown). */
const DASHBOARD_ITEM = { label: 'Dashboard', href: '/dashboard', icon: I.home };

/* ---------------------------------------------------------------------- */
/* Admin / staff sidebar                                                  */
/* ---------------------------------------------------------------------- */

export const sidebarSections = [
    {
        items: [DASHBOARD_ITEM],
    },
    {
        title: 'Sales Operations',
        icon: I.cart,
        items: [
            { label: 'Orders', href: '/orders', icon: I.cart, permission: 'orders.view' },
            { label: 'Create Order', href: '/orders/create', icon: I.plus, permission: 'orders.create' },
            { label: 'Customers', href: '/customers', icon: I.users, permission: 'customers.view' },
            { label: 'Returns', href: '/returns', icon: I.revert, permission: 'returns.view' },
            { label: 'Collections', href: '/collections', icon: I.cash, permission: 'collections.view' },
        ],
    },
    {
        title: 'Inventory & Products',
        icon: I.box,
        items: [
            { label: 'Products', href: '/products', icon: I.box, permission: 'products.view' },
            { label: 'Warehouses', href: '/warehouses', icon: I.box, permission: 'inventory.view' },
            { label: 'Inventory', href: '/inventory', icon: I.layers, permission: 'inventory.view' },
            { label: 'Stock Movements', href: '/inventory/movements', icon: I.layers, permission: 'inventory.view_movements' },
            { label: 'Stock Adjustments', href: '/stock-adjustments', icon: I.layers, permission: 'inventory.view_movements' },
            { label: 'Stock Counts', href: '/stock-counts', icon: I.layers, permission: 'inventory.count' },
            { label: 'Low Stock', href: '/inventory/low-stock', icon: I.warning, permission: 'inventory.view' },
        ],
    },
    {
        title: 'Purchasing',
        icon: I.receipt,
        items: [
            { label: 'Suppliers', href: '/suppliers', icon: I.users, permission: 'suppliers.view' },
            { label: 'Purchase Invoices', href: '/purchase-invoices', icon: I.receipt, permission: 'purchases.view' },
        ],
    },
    {
        title: 'Shipping & Fulfillment',
        icon: I.truck,
        items: [
            { label: 'Shipping Dashboard', href: '/shipping', icon: I.truck, permission: 'shipping.view' },
            { label: 'Ready to Pack', href: '/shipping/ready-to-pack', icon: I.truck, permission: 'shipping.view' },
            { label: 'Ready to Ship', href: '/shipping/ready-to-ship', icon: I.truck, permission: 'shipping.view' },
            { label: 'Shipments', href: '/shipping/shipments', icon: I.truck, permission: 'shipping.view' },
            { label: 'Carriers & Rates', href: '/shipping-companies', icon: I.truck, permission: 'shipping.view' },
            { label: 'Delayed Shipments', href: '/shipping/delayed', icon: I.warning, permission: 'shipping.view' },
            { label: '4×6 Labels', href: '/shipping-labels', icon: I.receipt, permission: 'shipping.print_label' },
        ],
    },
    {
        title: 'Finance Operations',
        icon: I.cash,
        items: [
            { label: 'Expenses', href: '/expenses', icon: I.cash, permission: 'expenses.view' },
            { label: 'Collections', href: '/collections', icon: I.cash, permission: 'collections.view' },
        ],
    },
    {
        title: 'Marketing & Ads',
        icon: I.megaphone,
        items: [
            { label: 'Ads', href: '/ads', icon: I.megaphone, permission: 'ads.view' },
        ],
    },
    {
        title: 'Marketers',
        icon: I.person,
        items: [
            { label: 'Marketers', href: '/marketers', icon: I.person, permission: 'marketers.view' },
        ],
    },
    {
        title: 'Customer Service',
        icon: I.ticket,
        items: [
            // Tickets is currently a stub (ModuleStubController). Show it
            // anyway so support staff know where it will live, but only
            // when tickets-related permission lands; for now, no permission
            // gate so it stays visible during the stub phase.
            { label: 'Tickets', href: '/tickets', icon: I.ticket },
        ],
    },
    {
        title: 'Reports',
        icon: I.chartPie,
        items: [
            { label: 'Sales Reports', href: '/reports/sales', icon: I.chartPie, permission: 'reports.sales' },
            { label: 'Inventory Reports', href: '/reports/inventory', icon: I.chartPie, permission: 'reports.inventory' },
            { label: 'Shipping Reports', href: '/reports/shipping', icon: I.chartPie, permission: 'reports.shipping' },
            { label: 'Collections Reports', href: '/reports/collections', icon: I.chartPie, permission: 'reports.cash_flow' },
            { label: 'Returns Reports', href: '/reports/returns', icon: I.chartPie, permission: 'reports.profit' },
            { label: 'Profit Report', href: '/reports/profit', icon: I.chartPie, permission: 'reports.profit' },
            { label: 'Ads Reports', href: '/reports/ads', icon: I.chartPie, permission: 'reports.ads' },
            { label: 'Marketer Reports', href: '/reports/marketers', icon: I.chartPie, permission: 'reports.marketers' },
            { label: 'Staff Target Reports', href: '/reports/staff', icon: I.chartPie, permission: 'reports.staff' },
            { label: 'Cash Flow Report', href: '/reports/cash-flow', icon: I.chartPie, permission: 'reports.cash_flow' },
        ],
    },
    {
        title: 'Administration',
        icon: I.cog,
        items: [
            { label: 'Users', href: '/users', icon: I.users, permission: 'users.manage' },
            { label: 'Roles & Permissions', href: '/roles', icon: I.check, permission: 'roles.manage' },
            { label: 'Staff & Targets', href: '/staff/targets', icon: I.target, permission: 'users.manage' },
            { label: 'Approval Requests', href: '/approvals', icon: I.check, permission: 'approvals.manage' },
            { label: 'Notifications', href: '/notifications', icon: I.bell },
            { label: 'Audit Logs', href: '/audit-logs', icon: I.log, permission: 'audit_logs.view' },
            { label: 'Settings', href: '/settings', icon: I.cog, permission: 'settings.manage' },
        ],
    },
    {
        title: 'System Tools',
        icon: I.archive,
        items: [
            { label: 'Import / Export', href: '/import-export', icon: I.download, permission: ['orders.import', 'orders.export', 'products.import', 'products.export', 'expenses.export'] },
            { label: 'Backups', href: '/backups', icon: I.archive, permission: 'backup.manage' },
            { label: 'Year-End Closing', href: '/year-end', icon: I.closeYear, permission: 'year_end.manage' },
        ],
    },
];

/* ---------------------------------------------------------------------- */
/* Marketer portal sidebar (used when auth.user.is_marketer === true)     */
/* ---------------------------------------------------------------------- */

export const marketerSidebarSections = [
    {
        items: [
            { label: 'My Dashboard', href: '/marketer/dashboard', icon: I.home },
        ],
    },
    {
        title: 'My Portal',
        icon: I.person,
        items: [
            { label: 'My Orders', href: '/marketer/orders', icon: I.cart },
            { label: 'My Products', href: '/marketer/products', icon: I.box },
            { label: 'My Wallet', href: '/marketer/wallet', icon: I.cash },
            { label: 'My Statement', href: '/marketer/statement', icon: I.receipt },
        ],
    },
];
