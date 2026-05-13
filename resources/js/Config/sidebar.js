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

    /* ----- Sidebar Phase B: distinct icons to break up duplicates ----- */
    // squares-2x2 → admin Dashboard
    dashboard: 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z',
    // shopping-bag → admin Orders
    shoppingBag: 'M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007ZM8.625 10.5a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm7.5 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
    // document-plus → Create Order
    documentPlus: 'M9 13.5h6m-3-3v6m-9 1.5V6a2.25 2.25 0 0 1 2.25-2.25h6.879a2.25 2.25 0 0 1 1.59.659l4.122 4.122a2.25 2.25 0 0 1 .659 1.591V18a2.25 2.25 0 0 1-2.25 2.25H5.25A2.25 2.25 0 0 1 3 18Z',
    // cube → Products (same shape as old `box` but kept as a semantic alias)
    cube: 'M21 7.5l-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    // tag → Categories
    tag: 'M9.568 3H5.25A2.25 2.25 0 0 0 3 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 0 0 5.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 0 0 9.568 3Z M6 6h.008v.008H6V6Z',
    // building-storefront → Warehouses
    buildingStorefront: 'M13.5 21v-7.5a.75.75 0 0 1 .75-.75h3a.75.75 0 0 1 .75.75V21m-4.5 0H2.36m11.14 0H18m0 0h3.64m-1.39 0V9.349m-16.5 11.65V9.35m0 0a3.001 3.001 0 0 0 3.75-.615A2.993 2.993 0 0 0 9.75 9.75c.896 0 1.7-.393 2.25-1.016a2.993 2.993 0 0 0 2.25 1.016c.896 0 1.7-.393 2.25-1.016a3.001 3.001 0 0 0 3.75.614m-16.5 0a3.004 3.004 0 0 1-.621-4.72l1.189-1.19A4.5 4.5 0 0 1 6.31 3h11.38a4.5 4.5 0 0 1 3.182 1.318l1.19 1.19a3.003 3.003 0 0 1-.621 4.72m-13.5 8.65h3.75a.75.75 0 0 0 .75-.75V13.5a.75.75 0 0 0-.75-.75H6.75a.75.75 0 0 0-.75.75v3.75c0 .415.336.75.75.75Z',
    // archive-box → Inventory
    archiveBox: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
    // arrows-right-left → Stock Movements & Cashbox Transfers
    arrowsRightLeft: 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
    // arrow-uturn-down → Returns (distinct from Refunds)
    arrowUturnDown: 'M3 7.5 7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0a4.5 4.5 0 1 1-9 0',
    // banknotes → Cashboxes
    banknotes: 'M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm3 0h.008v.008H18V12Zm-12 0h.008v.008H6V12Z',
    // credit-card → Payment Methods
    creditCard: 'M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 0 0 2.25-2.25V6.75A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25v10.5A2.25 2.25 0 0 0 4.5 19.5Z',
    // receipt-percent → Expenses
    receiptPercent: 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185ZM9.75 9h.008v.008H9.75V9Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm4.125 6h.008v.008h-.008V15Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
    // inbox-arrow-down → Collections
    inboxArrowDown: 'M9 8.25H7.5a2.25 2.25 0 0 0-2.25 2.25v9a2.25 2.25 0 0 0 2.25 2.25h9a2.25 2.25 0 0 0 2.25-2.25v-9a2.25 2.25 0 0 0-2.25-2.25H15M9 12l3 3m0 0 3-3m-3 3V2.25',
    // currency-dollar → Marketer Payouts
    currencyDollar: 'M12 6v12m-3-2.818.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-2.485 0-4.5-1.79-4.5-4 0-2.21 2.015-4 4.5-4s4.5 1.79 4.5 4M3 12a9 9 0 1 1 18 0 9 9 0 0 1-18 0Z',
    // calendar-days → Finance Periods
    calendarDays: 'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z',
    // briefcase → Marketers
    briefcase: 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.16 2.16 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0M12 12.75h.008v.008H12v-.008Z',
    // user-circle → Users (admin) — distinct from `users` used for Customers/Suppliers
    userCircle: 'M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.963 0a9 9 0 1 0-11.963 0m11.963 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',

    /* ----- Phase B refinement: break up remaining duplicates ----- */
    // chart-bar → Shipping Dashboard
    chartBar: 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
    // chart-bar-square → Finance Reports (different from generic Reports chartPie)
    chartBarSquare: 'M7.5 14.25v2.25m3-4.5v4.5m3-6.75v6.75m3-9v9M6 20.25h12A2.25 2.25 0 0 0 20.25 18V6A2.25 2.25 0 0 0 18 3.75H6A2.25 2.25 0 0 0 3.75 6v12A2.25 2.25 0 0 0 6 20.25Z',
    // key → Roles & Permissions (distinct from check used for Approval Requests)
    key: 'M15.75 5.25a3 3 0 0 1 3 3m3 0a6 6 0 0 1-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1 1 21.75 8.25Z',
    // queue-list → Ready to Pack (waiting queue)
    queueList: 'M3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 17.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM7.5 6.75h12.75M7.5 12h12.75m-12.75 5.25h12.75',
    // building-office-2 → Carriers & Rates (carrier company)
    buildingOffice2: 'M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21',
    // printer → 4×6 Labels
    printer: 'M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0 1 10.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0 .229 2.523a1.125 1.125 0 0 1-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0 0 21 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 0 0-1.913-.247M6.34 18H5.25A2.25 2.25 0 0 1 3 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 0 1 1.913-.247m10.5 0a48.536 48.536 0 0 0-10.5 0m10.5 0V3.375c0-.621-.504-1.125-1.125-1.125h-8.25c-.621 0-1.125.504-1.125 1.125v3.659M18 10.5h.008v.008H18V10.5Zm-3 0h.008v.008H15V10.5Z',
    // wrench → Stock Adjustments
    wrench: 'M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085',
    // clipboard-document-check → Stock Counts (verified count)
    clipboardCheck: 'M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192v4.158M16.5 18.75h-7.5M16.5 21.75H7.5A2.25 2.25 0 0 1 5.25 19.5V9.375c0-.621.504-1.125 1.125-1.125h11.25c.621 0 1.125.504 1.125 1.125v8.25m-3.75-3 1.5 1.5 3-3.75',
    // archive-box-arrow-down → Backups
    archiveBoxDown: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
};

/* Top-level "Dashboard" item appears outside any group (always shown). */
const DASHBOARD_ITEM = { label: 'Dashboard', href: '/dashboard', icon: I.dashboard };

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
            { label: 'Orders', href: '/orders', icon: I.shoppingBag, permission: 'orders.view' },
            { label: 'Create Order', href: '/orders/create', icon: I.documentPlus, permission: 'orders.create' },
            { label: 'Customers', href: '/customers', icon: I.users, permission: 'customers.view' },
            { label: 'Returns', href: '/returns', icon: I.arrowUturnDown, permission: 'returns.view' },
            { label: 'Collections', href: '/collections', icon: I.inboxArrowDown, permission: 'collections.view' },
        ],
    },
    {
        title: 'Shipping & Fulfillment',
        icon: I.truck,
        items: [
            { label: 'Ready to Pack', href: '/shipping/ready-to-pack', icon: I.queueList, permission: 'shipping.view' },
            { label: 'Ready to Ship', href: '/shipping/ready-to-ship', icon: I.truck, permission: 'shipping.view' },
            { label: 'Shipping Dashboard', href: '/shipping', icon: I.chartBar, permission: 'shipping.view' },
            { label: 'Shipments', href: '/shipping/shipments', icon: I.archiveBox, permission: 'shipping.view' },
            { label: 'Carriers & Rates', href: '/shipping-companies', icon: I.buildingOffice2, permission: 'shipping.view' },
            { label: 'Delayed Shipments', href: '/shipping/delayed', icon: I.warning, permission: 'shipping.view' },
            { label: '4×6 Labels', href: '/shipping-labels', icon: I.printer, permission: 'shipping.print_label' },
        ],
    },
    {
        title: 'Inventory & Products',
        icon: I.box,
        items: [
            { label: 'Products', href: '/products', icon: I.cube, permission: 'products.view' },
            { label: 'Categories', href: '/categories', icon: I.tag, permission: 'products.view' },
            { label: 'Warehouses', href: '/warehouses', icon: I.buildingStorefront, permission: 'inventory.view' },
            { label: 'Inventory', href: '/inventory', icon: I.archiveBox, permission: 'inventory.view' },
            { label: 'Stock Movements', href: '/inventory/movements', icon: I.arrowsRightLeft, permission: 'inventory.view_movements' },
            { label: 'Stock Adjustments', href: '/stock-adjustments', icon: I.wrench, permission: 'inventory.view_movements' },
            { label: 'Stock Counts', href: '/stock-counts', icon: I.clipboardCheck, permission: 'inventory.count' },
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
        title: 'Finance Operations',
        icon: I.cash,
        items: [
            { label: 'Cashboxes', href: '/cashboxes', icon: I.banknotes, permission: 'cashboxes.view' },
            { label: 'Cashbox Transfers', href: '/cashbox-transfers', icon: I.arrowsRightLeft, permission: 'cashbox_transfers.view' },
            { label: 'Payment Methods', href: '/payment-methods', icon: I.creditCard, permission: 'payment_methods.view' },
            { label: 'Expenses', href: '/expenses', icon: I.receiptPercent, permission: 'expenses.view' },
            { label: 'Collections', href: '/collections', icon: I.inboxArrowDown, permission: 'collections.view' },
            { label: 'Refunds', href: '/refunds', icon: I.revert, permission: 'refunds.view' },
            { label: 'Marketer Payouts', href: '/marketer-payouts', icon: I.currencyDollar, permission: 'marketer_payouts.view' },
            { label: 'Finance Reports', href: '/finance/reports', icon: I.chartBarSquare, permission: 'finance_reports.view' },
            { label: 'Finance Periods', href: '/finance/periods', icon: I.calendarDays, permission: 'finance_periods.view' },
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
            { label: 'Marketers', href: '/marketers', icon: I.briefcase, permission: 'marketers.view' },
        ],
    },
    {
        title: 'Customer Service',
        icon: I.ticket,
        items: [
            // Phase 7 — real Tickets module replaces the previous stub.
            // Visible only for users with view OR create capability.
            { label: 'Tickets', href: '/tickets', icon: I.ticket, permission: ['tickets.view', 'tickets.create'] },
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
            { label: 'Users', href: '/users', icon: I.userCircle, permission: 'users.manage' },
            { label: 'Roles & Permissions', href: '/roles', icon: I.key, permission: 'roles.manage' },
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
            { label: 'Backups', href: '/backups', icon: I.archiveBoxDown, permission: 'backup.manage' },
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
