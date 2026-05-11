import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import StatusBadge from '@/Components/StatusBadge';
import useCan from '@/Hooks/useCan';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * Admin operational dashboard.
 *
 * Marketer users are redirected server-side (DashboardController) to
 * /marketer/dashboard so they never reach this view.
 *
 * KPIs are grouped into workflow sections — Today Snapshot, Sales
 * Operations, Fulfillment Operations, Inventory Alerts, Finance,
 * Support — so each role can scan the row that matters to them.
 * Permission-locked sections collapse to nothing when the user lacks
 * visibility.
 *
 * Period selector (`?period=today|7d|mtd|fytd`) re-frames the Today
 * Snapshot tiles only. Aggregate MTD-named tiles (Delivery Rate,
 * AOV, Expenses) keep their MTD framing because the metric name
 * itself implies the window.
 */

/* ────────────────────── Building blocks ────────────────────── */

function KpiCard({ label, value, hint, deltaText, deltaTone, accent, href }) {
    const accentColors = {
        slate: 'border-slate-200',
        emerald: 'border-emerald-200',
        amber: 'border-amber-200',
        red: 'border-red-200',
        indigo: 'border-indigo-200',
    };
    const toneColors = {
        up: 'text-emerald-600',
        down: 'text-red-600',
        flat: 'text-slate-400',
    };
    const baseClass = 'block rounded-lg border bg-white p-4 shadow-sm transition ' + (accentColors[accent] ?? accentColors.slate);
    const interactiveClass = href ? ' hover:-translate-y-0.5 hover:border-slate-300 hover:shadow' : '';
    const body = (
        <>
            <div className="flex items-start justify-between gap-2">
                <div className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{label}</div>
                {href && (
                    <span className="text-[11px] text-slate-400" aria-hidden>→</span>
                )}
            </div>
            <div className="mt-1.5 text-2xl font-semibold tabular-nums text-slate-900">{value}</div>
            <div className="mt-1 flex items-center gap-2 text-[11px]">
                {deltaText && (
                    <span className={toneColors[deltaTone] ?? toneColors.flat}>{deltaText}</span>
                )}
                {hint && <span className="text-slate-400">{hint}</span>}
            </div>
        </>
    );
    return href
        ? <Link href={href} className={baseClass + interactiveClass}>{body}</Link>
        : <div className={baseClass}>{body}</div>;
}

function deltaParts(curr, prev) {
    if (prev === 0 && curr === 0) return { text: 'no change vs yesterday', tone: 'flat' };
    if (prev === 0 && curr > 0) return { text: '↑ new today (none yesterday)', tone: 'up' };
    if (prev > 0 && curr === 0) return { text: '↓ none today (was ' + prev + ' yesterday)', tone: 'down' };
    const pct = Math.round(((curr - prev) / prev) * 100);
    if (pct > 0) return { text: `↑ ${pct}% vs yesterday`, tone: 'up' };
    if (pct < 0) return { text: `↓ ${Math.abs(pct)}% vs yesterday`, tone: 'down' };
    return { text: 'flat vs yesterday', tone: 'flat' };
}

function Money({ value, sym }) {
    const v = Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return <span>{sym}{v}</span>;
}

/**
 * Lightweight bar chart as inline SVG. Pure CSS/SVG — no chart lib.
 */
function MiniBarChart({ series, valueFormatter, color = '#4f46e5' }) {
    if (!series?.length) return <EmptyState text="No data yet" />;
    const max = Math.max(...series.map((p) => Number(p.value) || 0), 1);
    const w = 100 / series.length;
    return (
        <div>
            <svg viewBox="0 0 100 50" className="h-32 w-full" preserveAspectRatio="none">
                {series.map((p, i) => {
                    const h = (Number(p.value) / max) * 45;
                    return (
                        <rect
                            key={i}
                            x={i * w + w * 0.15}
                            y={50 - h}
                            width={w * 0.7}
                            height={Math.max(h, 0.5)}
                            fill={color}
                            opacity={0.85}
                        >
                            <title>{p.date}: {valueFormatter ? valueFormatter(p.value) : p.value}</title>
                        </rect>
                    );
                })}
            </svg>
            <div className="mt-1 flex justify-between text-[10px] text-slate-400">
                <span>{series[0]?.date?.slice(5)}</span>
                <span>{series[series.length - 1]?.date?.slice(5)}</span>
            </div>
        </div>
    );
}

/**
 * Horizontal bar list — used for status distribution and the
 * shipments-by-status widget. Same pattern, different colour accent.
 */
function HBarList({ data, total, barColor = 'bg-indigo-500' }) {
    if (!data?.length) return <EmptyState text="Nothing to show yet" />;
    const max = Math.max(...data.map((d) => d.count), 1);
    return (
        <ul className="space-y-2">
            {data.map((d) => {
                const pct = max > 0 ? Math.round((d.count / max) * 100) : 0;
                const sharePct = total > 0 ? Math.round((d.count / total) * 100) : 0;
                return (
                    <li key={d.status}>
                        <div className="flex items-center justify-between text-xs">
                            <StatusBadge value={d.status} />
                            <span className="tabular-nums text-slate-600">
                                {d.count} <span className="text-slate-400">({sharePct}%)</span>
                            </span>
                        </div>
                        <div className="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div className={'h-full rounded-full ' + barColor} style={{ width: pct + '%' }} />
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}

function Card({ title, action, children, padded = true }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
            {(title || action) && (
                <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                    <h2 className="text-sm font-semibold text-slate-700">{title}</h2>
                    {action}
                </div>
            )}
            <div className={padded ? 'p-4' : ''}>{children}</div>
        </div>
    );
}

function EmptyState({ text }) {
    return (
        <div className="flex h-24 items-center justify-center rounded-md border border-dashed border-slate-200 text-xs text-slate-400">
            {text}
        </div>
    );
}

function AlertRow({ label, count, href, tone = 'slate' }) {
    const toneClass = {
        slate: 'text-slate-700',
        amber: 'text-amber-700',
        red: 'text-red-700',
        indigo: 'text-indigo-700',
    }[tone] ?? 'text-slate-700';
    const body = (
        <div className="flex items-center justify-between rounded-md px-3 py-2 hover:bg-slate-50">
            <span className={'text-sm ' + toneClass}>{label}</span>
            <span className={'rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ' + (count > 0 ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500')}>
                {count}
            </span>
        </div>
    );
    return href ? <Link href={href}>{body}</Link> : body;
}

function QuickAction({ href, label, icon }) {
    return (
        <Link
            href={href}
            className="flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700 shadow-sm transition hover:border-slate-300 hover:bg-slate-50"
        >
            <span className="text-base">{icon}</span>
            <span>{label}</span>
        </Link>
    );
}

/**
 * Section header + grid. Children are the KPI cards. The section
 * collapses to null if no children render (all were permission-locked).
 */
function Section({ title, children }) {
    const items = Array.isArray(children) ? children.filter(Boolean) : (children ? [children] : []);
    if (items.length === 0) return null;
    return (
        <section className="mt-6">
            <h2 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">{title}</h2>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                {items}
            </div>
        </section>
    );
}

/**
 * Period selector — 4 segmented buttons that drive `?period=` on
 * the dashboard URL. The current value is highlighted; clicking a
 * different value triggers a normal Inertia visit.
 *
 * URL is the source of truth (shareable / bookmarkable). No local
 * storage persistence — that would require an extra round-trip on
 * each dashboard visit.
 */
function PeriodSelector({ value, options, dashboardUrl }) {
    const labelFor = (key) => ({
        today: 'Today',
        '7d': '7d',
        mtd: 'MTD',
        fytd: 'FYTD',
    }[key] ?? key);
    return (
        <div role="group" aria-label="Date period" className="inline-flex rounded-md border border-slate-200 bg-white p-0.5 shadow-sm">
            {options.map((opt) => {
                const active = opt === value;
                const classes = active
                    ? 'rounded bg-indigo-600 px-2.5 py-1 text-xs font-semibold text-white'
                    : 'rounded px-2.5 py-1 text-xs font-medium text-slate-600 hover:text-slate-900';
                return (
                    <Link
                        key={opt}
                        href={dashboardUrl + (opt === 'today' ? '' : '?period=' + opt)}
                        className={classes}
                        preserveScroll
                        aria-current={active ? 'page' : undefined}
                    >
                        {labelFor(opt)}
                    </Link>
                );
            })}
        </div>
    );
}

/* ────────────────────── Page ────────────────────── */

export default function Dashboard({ period, kpis, widgets, charts, tables, alerts, permissions }) {
    const { props } = usePage();
    const user = props.auth?.user;
    const sym = props.app?.currency_symbol ?? '';
    const can = useCan();

    const isToday = period?.value === 'today';
    const periodLabel = period?.label ?? 'Today';

    // Yesterday-comparison delta is only meaningful for period=today.
    // For other periods the server omits the comparison values.
    const ordersDelta = isToday ? deltaParts(Number(kpis?.orders_period || 0), Number(kpis?.orders_compare || 0)) : null;
    const salesDelta = isToday ? deltaParts(Number(kpis?.sales_period || 0), Number(kpis?.sales_compare || 0)) : null;

    const statusTotal = (charts?.status_distribution ?? []).reduce((acc, d) => acc + d.count, 0);
    const shipmentsTotal = (widgets?.shipments_by_status ?? []).reduce((acc, d) => acc + d.count, 0);

    const canViewOrders = permissions?.orders_view ?? can('orders.view');
    const canViewTickets = permissions?.tickets_view ?? can('tickets.view');
    const canViewShipping = permissions?.shipping_view ?? can('shipping.view');
    const canViewInventory = permissions?.inventory_view ?? can('inventory.view');
    const canViewExpenses = permissions?.expenses_view ?? can('expenses.view');

    const deliveryRate = kpis?.delivery_rate_mtd; // null when no resolved orders
    const deliveryRateText = deliveryRate == null ? '—' : `${deliveryRate}%`;
    const deliveryRateHint = kpis?.delivery_rate_mtd_resolved != null
        ? `${kpis.delivery_rate_mtd_delivered ?? 0} / ${kpis.delivery_rate_mtd_resolved} resolved`
        : undefined;

    const dashboardUrl = route('dashboard');

    return (
        <AuthenticatedLayout header="Dashboard">
            <Head title="Dashboard" />

            <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 className="text-xl font-semibold text-slate-800">
                        Welcome back, {user?.name?.split(' ')[0] ?? 'operator'}
                    </h1>
                    <p className="text-sm text-slate-500">
                        {user?.role?.name} · {props.app?.country} · {props.app?.currency_code}
                    </p>
                </div>
                <div className="flex items-center gap-3">
                    <PeriodSelector
                        value={period?.value ?? 'today'}
                        options={period?.options ?? ['today', '7d', 'mtd', 'fytd']}
                        dashboardUrl={dashboardUrl}
                    />
                    <p className="hidden text-xs text-slate-400 sm:block">
                        {new Date().toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })}
                    </p>
                </div>
            </div>

            {/* Today Snapshot — period-respecting tiles. Labels switch
                between Today / 7d / MTD / FYTD based on the selector. */}
            <Section title={`${periodLabel} Snapshot`}>
                <KpiCard
                    label={`Orders ${periodLabel}`}
                    value={kpis?.orders_period ?? 0}
                    deltaText={ordersDelta?.text}
                    deltaTone={ordersDelta?.tone}
                    accent="indigo"
                    href={can('orders.view') ? route('orders.index') : undefined}
                />
                <KpiCard
                    label={`Sales ${periodLabel}`}
                    value={<Money value={kpis?.sales_period} sym={sym} />}
                    deltaText={salesDelta?.text}
                    deltaTone={salesDelta?.tone}
                    accent="emerald"
                    href={can('reports.sales') ? route('reports.sales') : undefined}
                />
                <KpiCard
                    label={`Delivered ${periodLabel}`}
                    value={kpis?.delivered_period ?? 0}
                    hint={isToday && kpis?.delivered_mtd != null ? `${kpis.delivered_mtd} MTD` : undefined}
                    accent="emerald"
                    href={can('orders.view') ? route('orders.index') : undefined}
                />
                <KpiCard
                    label={`Collections ${periodLabel}`}
                    value={<Money value={kpis?.collections_period_amount} sym={sym} />}
                    hint={(kpis?.collections_period_count ?? 0) + ' collected'}
                    accent="emerald"
                    href={can('collections.view') ? route('collections.index') : undefined}
                />
                <KpiCard
                    label={`Returns ${periodLabel}`}
                    value={kpis?.returns_period ?? 0}
                    href={can('returns.view') ? route('returns.index') : undefined}
                />
            </Section>

            {/* Sales Operations — pipeline + quality + average ticket. */}
            <Section title="Sales Operations">
                <KpiCard
                    label="Pending orders"
                    value={kpis?.pending_orders ?? 0}
                    hint="New + Pending Conf. + Confirmed"
                    href={can('orders.view') ? route('orders.index') : undefined}
                />
                <KpiCard
                    label="Active customers (MTD)"
                    value={kpis?.active_customers_this_month ?? 0}
                    hint="Distinct customers"
                    href={can('customers.view') ? route('customers.index') : undefined}
                />
                {canViewOrders && (
                    <KpiCard
                        label="Delivery rate (MTD)"
                        value={deliveryRateText}
                        hint={deliveryRateHint}
                        accent="emerald"
                        href={can('reports.profit') ? route('reports.profit') : undefined}
                    />
                )}
                {canViewOrders && (
                    <KpiCard
                        label="Avg order value (MTD)"
                        value={<Money value={kpis?.avg_order_value_mtd} sym={sym} />}
                        hint={(kpis?.avg_order_value_mtd_count ?? 0) + ' orders this month'}
                        accent="indigo"
                        href={can('reports.sales') ? route('reports.sales') : undefined}
                    />
                )}
            </Section>

            {/* Fulfillment Operations — what's in flight right now. */}
            <Section title="Fulfillment Operations">
                <KpiCard
                    label="Ready to pack"
                    value={kpis?.ready_to_pack ?? 0}
                    hint="Awaiting packing"
                    href={can('shipping.view') ? route('shipping.ready-to-pack') : undefined}
                />
                <KpiCard
                    label="Ready to ship"
                    value={kpis?.ready_to_ship ?? 0}
                    hint="Packed + Ready to Ship"
                    href={can('shipping.view') ? route('shipping.ready-to-ship') : undefined}
                />
                <KpiCard
                    label="Active shipments"
                    value={kpis?.active_shipments ?? 0}
                    hint="Assigned → Out for Delivery"
                    accent="indigo"
                    href={can('shipping.view') ? route('shipping.shipments') : undefined}
                />
                <KpiCard
                    label="Delayed shipments"
                    value={kpis?.delayed_shipments ?? 0}
                    accent={kpis?.delayed_shipments > 0 ? 'red' : 'slate'}
                    href={can('shipping.view') ? route('shipping.delayed') : undefined}
                />
            </Section>

            {/* Inventory Alerts — including the new Out of Stock tile. */}
            <Section title="Inventory Alerts">
                <KpiCard
                    label="Low stock products"
                    value={kpis?.low_stock_products ?? 0}
                    accent={kpis?.low_stock_products > 0 ? 'amber' : 'slate'}
                    href={can('inventory.view') ? route('inventory.low-stock') : undefined}
                />
                {canViewInventory && (
                    <KpiCard
                        label="Out of stock"
                        value={kpis?.out_of_stock ?? 0}
                        hint="on_hand ≤ 0"
                        accent={kpis?.out_of_stock > 0 ? 'red' : 'slate'}
                        href={can('inventory.view') ? route('inventory.low-stock') : undefined}
                    />
                )}
            </Section>

            {/* Finance Snapshot — Expenses MTD (only when permitted). */}
            {canViewExpenses && kpis?.expenses_mtd != null && (
                <Section title="Finance Snapshot">
                    <KpiCard
                        label="Expenses (MTD)"
                        value={<Money value={kpis?.expenses_mtd} sym={sym} />}
                        href={route('expenses.index')}
                    />
                </Section>
            )}

            {/* Support / Tickets — only when permitted (Phase 1 behavior). */}
            {canViewTickets && kpis?.open_tickets != null && (
                <Section title="Support / Tickets">
                    <KpiCard
                        label="Open tickets"
                        value={kpis?.open_tickets ?? 0}
                        hint="open + in progress"
                        accent={kpis?.open_tickets > 0 ? 'amber' : 'slate'}
                        href={route('tickets.index')}
                    />
                </Section>
            )}

            {/* Quick Actions. */}
            <section className="mt-6">
                <h2 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Quick Actions</h2>
                <div className="flex flex-wrap gap-2">
                    {can('orders.create') && <QuickAction href={route('orders.create')} label="Create order" icon="🧾" />}
                    {can('shipping.view') && <QuickAction href={route('shipping.ready-to-pack')} label="Ready to pack" icon="📦" />}
                    {can('shipping.view') && <QuickAction href={route('shipping.ready-to-ship')} label="Ready to ship" icon="🚚" />}
                    {can('shipping.print_label') && <QuickAction href={route('shipping-labels.index')} label="Print labels" icon="🖨️" />}
                    {can('products.create') && <QuickAction href={route('products.create')} label="Add product" icon="📦" />}
                    {can('customers.create') && <QuickAction href={route('customers.create')} label="Add customer" icon="👤" />}
                    {can('tickets.create') && <QuickAction href={route('tickets.create')} label="Open ticket" icon="🎫" />}
                    {can('expenses.create') && <QuickAction href={route('expenses.create')} label="Record expense" icon="💸" />}
                    {can.any(['orders.import', 'orders.export', 'products.import', 'products.export', 'expenses.export']) && (
                        <QuickAction href={route('import-export.index')} label="Import / Export" icon="📤" />
                    )}
                </div>
            </section>

            {/* Charts + Shipments-by-Status widget. The widget replaces
                one of the chart cards when shipping permissions exist;
                otherwise the row stays at 3 columns. */}
            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card title="Orders trend (7 days)">
                    <MiniBarChart series={charts?.orders_trend} color="#6366f1" />
                </Card>
                <Card title="Sales trend (7 days)">
                    <MiniBarChart
                        series={charts?.sales_trend}
                        color="#10b981"
                        valueFormatter={(v) => `${sym}${Number(v).toFixed(2)}`}
                    />
                </Card>
                <Card title="Status distribution (this month)">
                    <HBarList data={charts?.status_distribution} total={statusTotal} />
                </Card>
            </div>

            {canViewShipping && widgets?.shipments_by_status?.length > 0 && (
                <div className="mt-4">
                    <Card title="Shipments by status">
                        <HBarList
                            data={widgets.shipments_by_status}
                            total={shipmentsTotal}
                            barColor="bg-sky-500"
                        />
                    </Card>
                </div>
            )}

            {/* Alerts + tables. */}
            <div className="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card title="Needs attention" padded={false}>
                    <div className="px-2 py-2">
                        <AlertRow label="Delayed shipments" count={alerts?.delayed_shipments ?? 0} href={route('shipping.delayed')} tone="red" />
                        <AlertRow label="Out of stock" count={alerts?.out_of_stock_products ?? 0} href={route('inventory.low-stock')} tone="red" />
                        <AlertRow label="Low stock products" count={alerts?.low_stock_products ?? 0} href={route('inventory.low-stock')} tone="amber" />
                        <AlertRow label="Returns pending inspection" count={alerts?.returns_pending_inspection ?? 0} href={route('returns.index')} />
                        <AlertRow label="Pending collections" count={alerts?.pending_collections ?? 0} href={route('collections.index')} />
                        {can('approvals.manage') && (
                            <AlertRow label="Pending approvals" count={alerts?.pending_approvals ?? 0} href={route('approvals.index')} tone="indigo" />
                        )}
                    </div>
                </Card>

                {canViewOrders && (
                    <Card title="Latest orders" action={<Link href={route('orders.index')} className="text-xs text-indigo-600 hover:underline">All orders →</Link>} padded={false}>
                        {tables?.latest_orders?.length ? (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-xs">
                                    <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                        <tr>
                                            <th className="px-3 py-2 text-left">Order</th>
                                            <th className="px-3 py-2 text-left">Customer</th>
                                            <th className="px-3 py-2 text-left">Status</th>
                                            <th className="px-3 py-2 text-right">Total</th>
                                            <th className="px-3 py-2 text-left">Collection</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {tables.latest_orders.map((o) => (
                                            <tr key={o.id} className="hover:bg-slate-50">
                                                <td className="whitespace-nowrap px-3 py-1.5">
                                                    <Link href={route('orders.show', o.id)} className="font-medium text-indigo-600 hover:underline">
                                                        {o.order_number}
                                                    </Link>
                                                </td>
                                                <td className="px-3 py-1.5 text-slate-700">{o.customer_name}</td>
                                                <td className="px-3 py-1.5"><StatusBadge value={o.status} /></td>
                                                <td className="whitespace-nowrap px-3 py-1.5 text-right tabular-nums text-slate-700">
                                                    <Money value={o.total_amount} sym={sym} />
                                                </td>
                                                <td className="px-3 py-1.5"><StatusBadge value={o.collection_status} /></td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : (
                            <div className="p-4"><EmptyState text="No orders yet — create your first one." /></div>
                        )}
                    </Card>
                )}

                <Card title="Low stock" action={<Link href={route('inventory.low-stock')} className="text-xs text-indigo-600 hover:underline">View all →</Link>} padded={false}>
                    {tables?.low_stock?.length ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2 text-left">SKU</th>
                                        <th className="px-3 py-2 text-left">Product</th>
                                        <th className="px-3 py-2 text-right">On hand</th>
                                        <th className="px-3 py-2 text-right">Reserved</th>
                                        <th className="px-3 py-2 text-right">Available</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {tables.low_stock.map((p) => (
                                        <tr key={p.product_id} className="hover:bg-slate-50">
                                            <td className="whitespace-nowrap px-3 py-1.5 font-mono text-[11px] text-slate-600">{p.sku}</td>
                                            <td className="px-3 py-1.5 text-slate-700">{p.name}</td>
                                            <td className="px-3 py-1.5 text-right tabular-nums text-slate-700">{p.on_hand}</td>
                                            <td className="px-3 py-1.5 text-right tabular-nums text-slate-500">{p.reserved}</td>
                                            <td className="px-3 py-1.5 text-right tabular-nums font-medium text-slate-800">{p.available}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-4"><EmptyState text="All products above their reorder level — nothing to restock." /></div>
                    )}
                </Card>
            </div>

            {/* Delayed shipments — full width */}
            <div className="mt-6">
                <Card title="Delayed shipments" action={<Link href={route('shipping.delayed')} className="text-xs text-indigo-600 hover:underline">View all →</Link>} padded={false}>
                    {tables?.delayed_shipments?.length ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-xs">
                                <thead className="bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500">
                                    <tr>
                                        <th className="px-3 py-2 text-left">Order #</th>
                                        <th className="px-3 py-2 text-left">Customer</th>
                                        <th className="px-3 py-2 text-left">Carrier</th>
                                        <th className="px-3 py-2 text-right">Delay (days)</th>
                                        <th className="px-3 py-2 text-left">Status</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {tables.delayed_shipments.map((s) => (
                                        <tr key={s.shipment_id} className="hover:bg-slate-50">
                                            <td className="whitespace-nowrap px-3 py-1.5">
                                                {s.order_id ? (
                                                    <Link href={route('orders.show', s.order_id)} className="font-medium text-indigo-600 hover:underline">
                                                        {s.order_number}
                                                    </Link>
                                                ) : '—'}
                                            </td>
                                            <td className="px-3 py-1.5 text-slate-700">{s.customer_name ?? '—'}</td>
                                            <td className="px-3 py-1.5 text-slate-600">{s.carrier ?? '—'}</td>
                                            <td className="px-3 py-1.5 text-right tabular-nums text-slate-700">{s.delay_days ?? '—'}</td>
                                            <td className="px-3 py-1.5"><StatusBadge value={s.status} /></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="p-4"><EmptyState text="No delayed shipments — all carriers on time" /></div>
                    )}
                </Card>
            </div>
        </AuthenticatedLayout>
    );
}
